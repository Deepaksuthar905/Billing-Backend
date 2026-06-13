<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PayBy;
use App\Models\PayIn;
use App\Models\Purchase;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LedgerController extends Controller
{
    /** Opening balance date: ledger movements on/after this date roll from PayBy.prebalance. */
    private const FISCAL_START = '2026-04-01';

    private function notDeletedScope(): \Closure
    {
        return fn ($q) => $q->whereNull('isdel')->orWhere('isdel', '!=', 1);
    }

    private function payInNotDeletedScope(): \Closure
    {
        return function ($q) {
            if (Schema::hasColumn('pay_in', 'isdel')) {
                $q->where('isdel', '!=', 1);
            }
        };
    }

    /**
     * Invoices that are fully paid on invoice row only (no pay_in rows) — cash in at invoice date.
     */
    private function invoicesForLedgerCredit(int $pbid, string $from, string $to)
    {
        $scope = $this->notDeletedScope();

        return Invoice::with('party')
            ->whereBetween('dt', [$from, $to])
            ->where($scope)
            ->where('payby', $pbid)
            ->where(function ($q) {
                $q->whereNull('balance')->orWhere('balance', '<=', 0);
            })
            ->whereDoesntHave('payIns', $this->payInNotDeletedScope());
    }

    /**
     * Pay-in received into this account (actual cash in — due invoices use pay_in only).
     */
    private function payInsForLedger(int $pbid, string $from, string $to)
    {
        $query = PayIn::with(['party', 'invoice'])
            ->whereBetween('dt', [$from, $to])
            ->where('payby', $pbid);

        $query->where($this->payInNotDeletedScope());

        return $query;
    }

    /**
     * Net cash effect: pay_in + paid invoices (no pay_in) − purchases − expenses ± journal.
     */
    private function netMovementForPayBy(int $pbid, string $from, string $to): float
    {
        $scope = $this->notDeletedScope();

        $invoiceTotal = (float) $this->invoicesForLedgerCredit($pbid, $from, $to)->sum('payment');

        $payInQuery = PayIn::whereBetween('dt', [$from, $to])->where('payby', $pbid);
        $payInQuery->where($this->payInNotDeletedScope());
        $payInTotal = (float) $payInQuery->sum('amount');

        $purchaseTotal = (float) Purchase::whereBetween('dt', [$from, $to])
            ->where($scope)
            ->where('payby', $pbid)
            ->sum('payment');

        $expenseTotal = (float) Expense::whereBetween('dt', [$from, $to])
            ->where($scope)
            ->where('payby', $pbid)
            ->sum('payment');

        $journalOut = (float) DB::table('journal_entry')
            ->whereBetween('dt', [$from, $to])
            ->where('isdel', '!=', 1)
            ->where('from_acc', $pbid)
            ->sum('amt');

        $journalIn = (float) DB::table('journal_entry')
            ->whereBetween('dt', [$from, $to])
            ->where('isdel', '!=', 1)
            ->where('to_acc', $pbid)
            ->sum('amt');

        return $invoiceTotal + $payInTotal - $purchaseTotal - $expenseTotal - $journalOut + $journalIn;
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'pbid' => ['required', 'integer', 'exists:pay_by,pbid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $pbid = (int) $request->input('pbid');
        $from = $request->input('from', now()->subDays(30)->format('Y-m-d'));
        $to = $request->input('to', now()->format('Y-m-d'));

        if ($from > $to) {
            return response()->json(['message' => 'from must be on or before to'], 422);
        }

        $payBy = PayBy::where('pbid', $pbid)->first();
        if (! $payBy) {
            return response()->json(['message' => 'Pay-by not found'], 404);
        }

        $fiscalStart = self::FISCAL_START;
        $effectiveFrom = max($from, $fiscalStart);

        if ($effectiveFrom > $to) {
            $openingBalance = (float) $payBy->prebalance;

            return response()->json([
                'from' => $from,
                'to' => $to,
                'summary' => [
                    'opening_balance' => round($openingBalance, 2),
                    'total_credit' => 0.0,
                    'total_debit' => 0.0,
                    'closing_balance' => round($openingBalance, 2),
                ],
                'entries' => [],
            ], 200);
        }

        $openingBalance = (float) $payBy->prebalance;
        if ($effectiveFrom > $fiscalStart) {
            $priorEnd = Carbon::parse($effectiveFrom)->subDay()->format('Y-m-d');
            if ($priorEnd >= $fiscalStart) {
                $openingBalance += $this->netMovementForPayBy($pbid, $fiscalStart, $priorEnd);
            }
        }

        $scope = $this->notDeletedScope();

        // CREDITS: pay_in only when money actually received (due invoice full amount NOT here)
        $payIns = $this->payInsForLedger($pbid, $effectiveFrom, $to)
            ->get()
            ->map(fn ($p) => [
                'date' => $p->dt?->format('Y-m-d'),
                'type' => 'pay_in',
                'ref_id' => $p->pinid,
                'ref_no' => $p->referal ?? (string) $p->pinid,
                'description' => trim(
                    ($p->party?->partyname ?? '-')
                    .($p->invoice?->inv_no ? ' · Inv '.$p->invoice->inv_no : '')
                    .($p->description ? ' · '.$p->description : '')
                ),
                'credit' => (float) $p->amount,
                'debit' => 0.0,
            ]);

        // CREDITS: invoice only if fully paid on invoice row and no pay_in (legacy / cash at bill)
        $invoices = $this->invoicesForLedgerCredit($pbid, $effectiveFrom, $to)
            ->get()
            ->map(fn ($inv) => [
                'date' => $inv->dt?->format('Y-m-d'),
                'type' => 'invoice',
                'ref_id' => $inv->invid,
                'ref_no' => $inv->inv_no,
                'description' => $inv->party?->partyname ?? '-',
                'credit' => (float) $inv->payment,
                'debit' => 0.0,
            ]);

        $purchases = Purchase::with('party')
            ->whereBetween('dt', [$effectiveFrom, $to])
            ->where($scope)
            ->where('payby', $pbid)
            ->get()
            ->map(fn ($p) => [
                'date' => $p->dt?->format('Y-m-d'),
                'type' => 'purchase',
                'ref_id' => $p->prid,
                'ref_no' => $p->p_inv_no,
                'description' => $p->party?->partyname ?? '-',
                'credit' => 0.0,
                'debit' => (float) $p->payment,
            ]);

        $expenses = Expense::with('expensesHead')
            ->whereBetween('dt', [$effectiveFrom, $to])
            ->where($scope)
            ->where('payby', $pbid)
            ->get()
            ->map(fn ($e) => [
                'date' => $e->dt?->format('Y-m-d'),
                'type' => 'expense',
                'ref_id' => $e->exid,
                'ref_no' => (string) ($e->receipt_no ?? ''),
                'description' => $e->expensesHead?->name ?? '-',
                'credit' => 0.0,
                'debit' => (float) $e->payment,
            ]);

        $journalOut = DB::table('journal_entry as j')
            ->leftJoin('pay_by as to_pb', 'to_pb.pbid', '=', 'j.to_acc')
            ->whereBetween('j.dt', [$effectiveFrom, $to])
            ->where('j.isdel', '!=', 1)
            ->where('j.from_acc', $pbid)
            ->get(['j.jid', 'j.dt', 'j.amt', 'j.detail', 'to_pb.name as to_name'])
            ->map(fn ($j) => [
                'date' => $j->dt,
                'type' => 'journal',
                'ref_id' => $j->jid,
                'ref_no' => (string) $j->jid,
                'description' => $j->detail ?: ('To '.($j->to_name ?? 'account')),
                'credit' => 0.0,
                'debit' => (float) $j->amt,
            ]);

        $journalIn = DB::table('journal_entry as j')
            ->leftJoin('pay_by as from_pb', 'from_pb.pbid', '=', 'j.from_acc')
            ->whereBetween('j.dt', [$effectiveFrom, $to])
            ->where('j.isdel', '!=', 1)
            ->where('j.to_acc', $pbid)
            ->get(['j.jid', 'j.dt', 'j.amt', 'j.detail', 'from_pb.name as from_name'])
            ->map(fn ($j) => [
                'date' => $j->dt,
                'type' => 'journal',
                'ref_id' => $j->jid,
                'ref_no' => (string) $j->jid,
                'description' => $j->detail ?: ('From '.($j->from_name ?? 'account')),
                'credit' => (float) $j->amt,
                'debit' => 0.0,
            ]);

        /** @var Collection<int, array<string, mixed>> $entries */
        $entries = $payIns
            ->concat($invoices)
            ->concat($purchases)
            ->concat($expenses)
            ->concat($journalOut)
            ->concat($journalIn)
            ->sortBy('date')
            ->values();

        $runningBalance = $openingBalance;

        $result = $entries->map(function (array $row) use (&$runningBalance) {
            $runningBalance += $row['credit'] - $row['debit'];
            $row['balance'] = round($runningBalance, 2);

            return $row;
        });

        $totalCredit = round($entries->sum('credit'), 2);
        $totalDebit = round($entries->sum('debit'), 2);
        $closing = $result->isNotEmpty()
            ? (float) $result->last()['balance']
            : round($openingBalance, 2);

        return response()->json([
            'from' => $from,
            'to' => $to,
            'summary' => [
                'opening_balance' => round($openingBalance, 2),
                'total_credit' => $totalCredit,
                'total_debit' => $totalDebit,
                'closing_balance' => round($closing, 2),
            ],
            'entries' => $result->values(),
        ], 200);
    }
}

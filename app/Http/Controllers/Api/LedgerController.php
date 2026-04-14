<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\PayBy;
use App\Models\Purchase;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class LedgerController extends Controller
{
    /** Opening balance date: ledger movements on/after this date roll from PayBy.prebalance. */
    private const FISCAL_START = '2026-04-01';

    private function notDeletedScope(): \Closure
    {
        return fn ($q) => $q->whereNull('isdel')->orWhere('isdel', '!=', 1);
    }

    /**
     * Net cash effect for one pay-by channel: invoice payments (credit) minus purchases and expenses (debit).
     */
    private function netMovementForPayBy(int $pbid, string $from, string $to): float
    {
        $scope = $this->notDeletedScope();

        $invoiceTotal = (float) Invoice::whereBetween('dt', [$from, $to])
            ->where($scope)
            ->where('payby', $pbid)
            ->sum('payment');

        $purchaseTotal = (float) Purchase::whereBetween('dt', [$from, $to])
            ->where($scope)
            ->where('payby', $pbid)
            ->sum('payment');

        $expenseTotal = (float) Expense::whereBetween('dt', [$from, $to])
            ->where($scope)
            ->where('payby', $pbid)
            ->sum('payment');

        return $invoiceTotal - $purchaseTotal - $expenseTotal;
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

        // No rows (e.g. to before 1 Apr while from is in March): opening stays FY start balance
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

        // Opening at start of visible window: 1 Apr prebalance + everything from 1 Apr through day before effectiveFrom
        $openingBalance = (float) $payBy->prebalance;
        if ($effectiveFrom > $fiscalStart) {
            $priorEnd = Carbon::parse($effectiveFrom)->subDay()->format('Y-m-d');
            if ($priorEnd >= $fiscalStart) {
                $openingBalance += $this->netMovementForPayBy($pbid, $fiscalStart, $priorEnd);
            }
        }

        $scope = $this->notDeletedScope();

        // ---- CREDITS: Invoices -------------------------------------------------
        $invoices = Invoice::with('party')
            ->whereBetween('dt', [$effectiveFrom, $to])
            ->where($scope)
            ->where('payby', $pbid)
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

        // ---- DEBITS: Purchases -------------------------------------------------
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

        // ---- DEBITS: Expenses --------------------------------------------------
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

        /** @var Collection<int, array<string, mixed>> $entries */
        $entries = $invoices
            ->concat($purchases)
            ->concat($expenses)
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

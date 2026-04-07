<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Purchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class LedgerController extends Controller
{
    /**
     * Ledger: date-wise debit/credit entries with running balance.
     *
     * Query params (all optional):
     *   from  - start date  Y-m-d  (default: 30 days ago)
     *   to    - end date    Y-m-d  (default: today)
     *
     * Logic:
     *   Invoice  → CREDIT  (+)  money that came in
     *   Purchase → DEBIT   (-)  money that went out on purchase
     *   Expense  → DEBIT   (-)  money that went out on expense
     *
     * Response:
     *   summary   : { total_credit, total_debit, closing_balance }
     *   entries   : [ { date, type, ref_id, ref_no, description, credit, debit, balance } ]
     */
    public function index(Request $request): JsonResponse
    {
        $from = $request->query('from', now()->subDays(30)->format('Y-m-d'));
        $to   = $request->query('to',   now()->format('Y-m-d'));

        // ---- CREDITS: Invoices  (isdel != 1 excluded) -------------------------
        $invoices = Invoice::with('party')
            ->whereBetween('dt', [$from, $to])
            ->where(fn ($q) => $q->whereNull('isdel')->orWhere('isdel', '!=', 1))
            ->get()
            ->map(fn ($inv) => [
                'date'        => $inv->dt?->format('Y-m-d'),
                'type'        => 'invoice',
                'ref_id'      => $inv->invid,
                'ref_no'      => $inv->inv_no,
                'description' => $inv->party?->partyname ?? '-',
                'credit'      => (float) $inv->payment,
                'debit'       => 0.0,
            ]);

        // ---- DEBITS: Purchases  (isdel != 1 excluded) -------------------------
        $purchases = Purchase::with('party')
            ->whereBetween('dt', [$from, $to])
            ->where(fn ($q) => $q->whereNull('isdel')->orWhere('isdel', '!=', 1))
            ->get()
            ->map(fn ($p) => [
                'date'        => $p->dt?->format('Y-m-d'),
                'type'        => 'purchase',
                'ref_id'      => $p->prid,
                'ref_no'      => $p->p_inv_no,
                'description' => $p->party?->partyname ?? '-',
                'credit'      => 0.0,
                'debit'       => (float) $p->payment,
            ]);

        // ---- DEBITS: Expenses  (isdel != 1 excluded) --------------------------
        $expenses = Expense::with('expensesHead')
            ->whereBetween('dt', [$from, $to])
            ->where(fn ($q) => $q->whereNull('isdel')->orWhere('isdel', '!=', 1))
            ->get()
            ->map(fn ($e) => [
                'date'        => $e->dt?->format('Y-m-d'),
                'type'        => 'expense',
                'ref_id'      => $e->exid,
                'ref_no'      => (string) ($e->receipt_no ?? ''),
                'description' => $e->expensesHead?->name ?? '-',
                'credit'      => 0.0,
                'debit'       => (float) $e->payment,
            ]);

        // ---- Merge & sort by date asc ----------------------------------------
        /** @var Collection $entries */
        $entries = $invoices
            ->concat($purchases)
            ->concat($expenses)
            ->sortBy('date')
            ->values();

        // ---- Running balance -------------------------------------------------
        $runningBalance = 0.0;
        $result = $entries->map(function (array $row) use (&$runningBalance) {
            $runningBalance += $row['credit'] - $row['debit'];
            $row['balance'] = round($runningBalance, 2);
            return $row;
        });

        $totalCredit = round($entries->sum('credit'), 2);
        $totalDebit  = round($entries->sum('debit'),  2);

        return response()->json([
            'from'    => $from,
            'to'      => $to,
            'summary' => [
                'total_credit'    => $totalCredit,
                'total_debit'     => $totalDebit,
                'closing_balance' => round($totalCredit - $totalDebit, 2),
            ],
            'entries' => $result->values(),
        ], 200);
    }
}

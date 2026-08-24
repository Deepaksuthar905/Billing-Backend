<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\MonthlyReport;
use App\Models\Purchase;
use App\Models\SalaryPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MonthlyReportController extends Controller
{
    private function notDeletedScope(): \Closure
    {
        return fn ($q) => $q->whereNull('isdel')->orWhere('isdel', '!=', 1);
    }

    /**
     * @return array{yr: int, mnth: int, from: string, to: string}
     */
    private function monthRange(string $month): array
    {
        [$y, $m] = explode('-', $month);
        $from = sprintf('%s-%s-01', $y, $m);
        $to = date('Y-m-t', strtotime($from));

        return ['yr' => (int) $y, 'mnth' => (int) $m, 'from' => $from, 'to' => $to];
    }

    /**
     * Compute all totals for a month from source tables.
     *
     * @return array<string, mixed>
     */
    private function computeMonth(string $month): array
    {
        $range = $this->monthRange($month);
        $from = $range['from'];
        $to = $range['to'];

        $invoiceRow = Invoice::query()
            ->where($this->notDeletedScope())
            ->whereBetween('dt', [$from, $to])
            ->selectRaw('
                COALESCE(SUM(payment), 0) AS inv_amt,
                COALESCE(SUM(balance), 0) AS due_amt,
                COALESCE(SUM(taxable_amt), 0) AS sales_taxable,
                COALESCE(SUM(cgst), 0) AS sales_cgst,
                COALESCE(SUM(sgst), 0) AS sales_sgst,
                COALESCE(SUM(igst), 0) AS sales_igst,
                COUNT(*) AS inv_count
            ')
            ->first();

        $purchaseRow = Purchase::query()
            ->where($this->notDeletedScope())
            ->whereBetween('dt', [$from, $to])
            ->selectRaw('
                COALESCE(SUM(payment), 0) AS purchase_amt,
                COALESCE(SUM(taxable_amt), 0) AS purchase_taxable,
                COALESCE(SUM(cgst), 0) AS purchase_cgst,
                COALESCE(SUM(sgst), 0) AS purchase_sgst,
                COALESCE(SUM(igst), 0) AS purchase_igst,
                COUNT(*) AS purchase_count
            ')
            ->first();

        $expenseRow = Expense::query()
            ->where($this->notDeletedScope())
            ->whereBetween('dt', [$from, $to])
            ->selectRaw('COALESCE(SUM(payment), 0) AS expense_amt, COUNT(*) AS expense_count')
            ->first();

        $salaryRow = SalaryPayment::query()
            ->where($this->notDeletedScope())
            ->whereBetween('dt', [$from, $to])
            ->selectRaw('COALESCE(SUM(net_amt), 0) AS salary_amt, COUNT(*) AS salary_count')
            ->first();

        $journalRow = DB::table('journal_entry')
            ->where('isdel', '!=', 1)
            ->whereBetween('dt', [$from, $to])
            ->selectRaw('COALESCE(SUM(amt), 0) AS journal_amt, COUNT(*) AS journal_count')
            ->first();

        $expenseHeads = Expense::query()
            ->from('expenses')
            ->leftJoin('expenses_head', 'expenses_head.exhid', '=', 'expenses.exhid')
            ->where(function ($q) {
                $q->whereNull('expenses.isdel')->orWhere('expenses.isdel', '!=', 1);
            })
            ->whereBetween('expenses.dt', [$from, $to])
            ->groupBy('expenses.exhid', 'expenses_head.name')
            ->orderByDesc(DB::raw('SUM(expenses.payment)'))
            ->selectRaw('expenses.exhid AS exhid, COALESCE(expenses_head.name, "Uncategorized") AS name, COALESCE(SUM(expenses.payment), 0) AS total, COUNT(*) AS count')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->exhid !== null ? (int) $row->exhid : null,
                'name' => (string) $row->name,
                'count' => (int) $row->count,
                'amount' => round((float) $row->total, 2),
            ])
            ->values()
            ->all();

        $invAmt = round((float) $invoiceRow->inv_amt, 2);
        $dueAmt = round((float) $invoiceRow->due_amt, 2);
        $purchaseAmt = round((float) $purchaseRow->purchase_amt, 2);
        $expenseAmt = round((float) $expenseRow->expense_amt, 2);
        $salaryAmt = round((float) $salaryRow->salary_amt, 2);

        return [
            'yr' => $range['yr'],
            'mnth' => $range['mnth'],
            'inv_amt' => $invAmt,
            'received_amt' => round($invAmt - $dueAmt, 2),
            'due_amt' => $dueAmt,
            'purchase_amt' => $purchaseAmt,
            'expense_amt' => $expenseAmt,
            'salary_amt' => $salaryAmt,
            'journal_amt' => round((float) $journalRow->journal_amt, 2),
            'inv_count' => (int) $invoiceRow->inv_count,
            'purchase_count' => (int) $purchaseRow->purchase_count,
            'expense_count' => (int) $expenseRow->expense_count,
            'salary_count' => (int) $salaryRow->salary_count,
            'journal_count' => (int) $journalRow->journal_count,
            'sales_taxable' => round((float) $invoiceRow->sales_taxable, 2),
            'purchase_taxable' => round((float) $purchaseRow->purchase_taxable, 2),
            'sales_cgst' => round((float) $invoiceRow->sales_cgst, 2),
            'sales_sgst' => round((float) $invoiceRow->sales_sgst, 2),
            'sales_igst' => round((float) $invoiceRow->sales_igst, 2),
            'purchase_cgst' => round((float) $purchaseRow->purchase_cgst, 2),
            'purchase_sgst' => round((float) $purchaseRow->purchase_sgst, 2),
            'purchase_igst' => round((float) $purchaseRow->purchase_igst, 2),
            'expense_heads' => $expenseHeads,
            'net_pl' => round($invAmt - $purchaseAmt - $expenseAmt - $salaryAmt, 2),
        ];
    }

    /**
     * Compute and store (upsert) the report for a month.
     */
    private function generateAndStore(string $month): MonthlyReport
    {
        $computed = $this->computeMonth($month);
        $computed['generated_at'] = now();

        return MonthlyReport::updateOrCreate(
            ['yr' => $computed['yr'], 'mnth' => $computed['mnth']],
            $computed
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function formatForResponse(MonthlyReport $report): array
    {
        $row = $report->toArray();
        $row['report_month'] = sprintf('%04d-%02d', $report->yr, $report->mnth);

        $decimalFields = [
            'inv_amt', 'received_amt', 'due_amt', 'purchase_amt', 'expense_amt',
            'salary_amt', 'journal_amt', 'sales_taxable', 'purchase_taxable',
            'sales_cgst', 'sales_sgst', 'sales_igst',
            'purchase_cgst', 'purchase_sgst', 'purchase_igst', 'net_pl',
        ];

        foreach ($decimalFields as $field) {
            $row[$field] = number_format((float) $report->getRawOriginal($field), 2, '.', '');
        }

        // Legacy aliases: old frontend reads these field names.
        $row['total_invoice_amt'] = $row['inv_amt'];
        $row['total_invoice_count'] = $report->inv_count;
        $row['total_expense_amt'] = $row['expense_amt'];
        $row['total_expense_count'] = $report->expense_count;
        $row['expense_by_head'] = collect($report->expense_heads ?? [])
            ->map(fn (array $head) => [
                'exhid' => $head['id'] ?? null,
                'name' => $head['name'] ?? '-',
                'count' => $head['count'] ?? 0,
                'total' => $head['amount'] ?? 0,
                'amount' => $head['amount'] ?? 0,
            ])
            ->values()
            ->all();
        $row['net_amt'] = number_format(
            (float) $report->getRawOriginal('inv_amt') - (float) $report->getRawOriginal('expense_amt'),
            2,
            '.',
            ''
        );

        return $row;
    }

    /**
     * POST /monthly-reports/generate  Body: { month: "YYYY-MM" }
     * Calculates all details for the month and stores in monthly_reports.
     */
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        [, $m] = explode('-', $validated['month']);
        if ((int) $m < 1 || (int) $m > 12) {
            return response()->json(['message' => 'Invalid month, must be between 01 and 12'], 422);
        }

        $existing = MonthlyReport::query()
            ->where('yr', (int) explode('-', $validated['month'])[0])
            ->where('mnth', (int) $m)
            ->first();

        if ($existing && (int) $existing->is_locked === 1) {
            return response()->json(['message' => 'This month is locked, report cannot be regenerated'], 422);
        }

        $report = $this->generateAndStore($validated['month']);

        return response()->json([
            'message' => 'Monthly report generated successfully',
            'data' => $this->formatForResponse($report),
        ], 200);
    }

    /**
     * GET /monthly-reports  Optional: ?year=2026
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $query = MonthlyReport::query();

        if ($request->filled('year')) {
            $query->where('yr', (int) $request->input('year'));
        }

        $reports = $query->orderByDesc('yr')->orderByDesc('mnth')->get();

        return response()->json([
            'message' => 'Monthly reports fetched successfully',
            'data' => $reports->map(fn (MonthlyReport $r) => $this->formatForResponse($r))->values(),
        ], 200);
    }

    /**
     * GET /monthly-reports/compare?month1=2025-07&month2=2026-07
     * Auto-generates a month if it is not stored yet, so comparison always works.
     */
    public function compare(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month1' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'month2' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $result = [];
        foreach (['month1', 'month2'] as $key) {
            $month = $validated[$key];
            [$y, $m] = explode('-', $month);

            $report = MonthlyReport::query()
                ->where('yr', (int) $y)
                ->where('mnth', (int) $m)
                ->first();

            if (! $report) {
                $report = $this->generateAndStore($month);
            }

            $result[$key] = $this->formatForResponse($report);
        }

        return response()->json([
            'message' => 'Monthly reports comparison fetched successfully',
            'data' => $result,
        ], 200);
    }
}

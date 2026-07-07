<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\PayBy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ExpenseController extends Controller
{
    /**
     * Map request payload → expenses table columns (only existing columns).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function mapExpensePayload(array $validated): array
    {
        $data = [
            'exhid' => $validated['exhid'],
            'description' => $validated['description'] ?? null,
            'payment' => $validated['payment'] ?? null,
            'taxable_amt' => $validated['taxable_amt'] ?? null,
            'dt' => $validated['dt'] ?? null,
            'party' => $validated['party'] ?? null,
            'payby' => $validated['payby'] ?? null,
            'refno' => $validated['refno'] ?? null,
        ];

        if (isset($validated['receipt_no'])) {
            $data['receipt_no'] = $validated['receipt_no'];
        }

        foreach (['payment', 'taxable_amt', 'gst', 'igst', 'cgst', 'sgst'] as $col) {
            if (! array_key_exists($col, $validated) || ! Schema::hasColumn('expenses', $col)) {
                continue;
            }

            $value = $validated[$col];
            $data[$col] = ($value === null || $value === '') ? null : round((float) $value, 2);
        }

        if (array_key_exists('state', $validated) && Schema::hasColumn('expenses', 'state')) {
            $data['state'] = $validated['state'];
        }

        if (array_key_exists('item_id', $validated) && Schema::hasColumn('expenses', 'item_id')) {
            $data['item_id'] = $validated['item_id'];
        }

        return $data;
    }

    /**
     * DB se raw decimal value read karke 2 decimal places ke saath return karta hai.
     */
    private function formatDecimalValue(Expense $expense, string $field): ?string
    {
        if (! array_key_exists($field, $expense->getAttributes())) {
            return null;
        }

        $raw = $expense->getRawOriginal($field);

        if ($raw === null || $raw === '') {
            return null;
        }

        return number_format((float) $raw, 2, '.', '');
    }

    /**
     * @return array<string, mixed>
     */
    private function formatExpenseForResponse(Expense $expense): array
    {
        $row = $expense->toArray();

        foreach (['payment', 'taxable_amt', 'gst', 'igst', 'cgst', 'sgst'] as $field) {
            $row[$field] = $this->formatDecimalValue($expense, $field);
        }

        return $row;
    }

    private function resolveExpensePayBy(array $validated): ?int
    {
        if (isset($validated['payby']) && $validated['payby'] !== '' && $validated['payby'] !== null) {
            return (int) $validated['payby'];
        }

        return PayBy::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', ['cash'])
            ->value('pbid');
    }

    /**
     * Create a new expense record.
     * receipt_no is auto-generated (next number) if not sent.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'exhid' => ['required', 'integer', 'exists:expenses_head,exhid'],
            'description' => ['nullable', 'string'],
            'receipt_no' => ['nullable', 'integer', 'min:1'],
            'payment' => ['nullable', 'numeric', 'min:0'],
            'taxable_amt' => ['nullable', 'numeric', 'min:0'],
            'gst' => ['nullable', 'numeric', 'min:0'],
            'igst' => ['nullable', 'numeric', 'min:0'],
            'cgst' => ['nullable', 'numeric', 'min:0'],
            'sgst' => ['nullable', 'numeric', 'min:0'],
            'dt' => ['nullable', 'date'],
            'state' => ['nullable', 'string', 'max:100'],
            'item_id' => ['nullable', 'integer', 'exists:items,item_id'],
            'party' => ['nullable', 'integer', 'exists:party,pid'],
            'payby' => ['nullable', 'integer', 'exists:pay_by,pbid'],
            'refno' => ['nullable', 'string', 'max:100'],
        ]);

        $validated['payby'] = $this->resolveExpensePayBy($validated);

        $data = $this->mapExpensePayload($validated);

        if (empty($data['receipt_no'])) {
            $data['receipt_no'] = (int) Expense::query()
                ->where(function ($q) {
                    $q->whereNull('isdel')->orWhere('isdel', '!=', 1);
                })
                ->max('receipt_no') + 1;
        }

        $expense = Expense::create($data);

        return response()->json([
            'message' => 'Expense created successfully',
            'data' => $this->formatExpenseForResponse($expense->load(['expensesHead', 'partyRelation'])),
        ], 201);
    }

    /**
     * Update an existing expense. PUT /api/expenses/{id} (id = exid).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $expense = Expense::query()
            ->where('exid', $id)
            ->where(function ($q) {
                $q->whereNull('isdel')->orWhere('isdel', '!=', 1);
            })
            ->first();

        if (! $expense) {
            return response()->json(['message' => 'Expense not found'], 404);
        }

        $validated = $request->validate([
            'exhid' => ['sometimes', 'integer', 'exists:expenses_head,exhid'],
            'description' => ['nullable', 'string'],
            'receipt_no' => ['nullable', 'integer', 'min:1'],
            'payment' => ['nullable', 'numeric', 'min:0'],
            'taxable_amt' => ['nullable', 'numeric', 'min:0'],
            'gst' => ['nullable', 'numeric', 'min:0'],
            'igst' => ['nullable', 'numeric', 'min:0'],
            'cgst' => ['nullable', 'numeric', 'min:0'],
            'sgst' => ['nullable', 'numeric', 'min:0'],
            'dt' => ['nullable', 'date'],
            'state' => ['nullable', 'string', 'max:100'],
            'item_id' => ['nullable', 'integer', 'exists:items,item_id'],
            'party' => ['nullable', 'integer', 'exists:party,pid'],
            'payby' => ['nullable', 'integer', 'exists:pay_by,pbid'],
            'refno' => ['nullable', 'string', 'max:100'],
        ]);

        $expense->update($this->mapExpensePayload($validated));

        return response()->json([
            'message' => 'Expense updated successfully',
            'data' => $this->formatExpenseForResponse($expense->fresh(['expensesHead', 'partyRelation'])),
        ], 200);
    }

    public function delexpense(Request $request, ?int $exid = null): JsonResponse
    {
        if ($exid !== null) {
            $request->merge(['exid' => $exid]);
        }

        $validated = $request->validate([
            'exid' => ['required', 'integer', 'exists:expenses,exid'],
        ]);

        $updated = Expense::query()
            ->where('exid', $validated['exid'])
            ->update(['isdel' => 1]);

        if ($updated === 0) {
            return response()->json(['message' => 'Expense not found'], 404);
        }

        return response()->json(['message' => 'Expense deleted successfully'], 200);
    }

    /**
     * Fetch expenses. Query: from=Y-m-d, to=Y-m-d (optional).
     */
    public function index(Request $request): JsonResponse
    {
        $from = $request->query('from');
        $to = $request->query('to');

        $query = Expense::with(['expensesHead', 'partyRelation'])->where(function ($q) {
            $q->whereNull('isdel')->orWhere('isdel', '!=', 1);
        });

        if ($from && $to) {
            $query->whereBetween('dt', [$from, $to]);
        } elseif ($from) {
            $query->whereDate('dt', '>=', $from);
        } elseif ($to) {
            $query->whereDate('dt', '<=', $to);
        }

        $expenses = $query->orderByDesc('dt')->get();

        return response()->json([
            'message' => 'Expenses fetched successfully',
            'data' => $expenses
                ->map(fn (Expense $expense) => $this->formatExpenseForResponse($expense))
                ->values(),
        ], 200);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
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
            'igst' => ['nullable', 'integer'],
            'cgst' => ['nullable', 'integer'],
            'sgst' => ['nullable', 'integer'],
            'dt' => ['nullable', 'date'],
            'party' => ['nullable', 'integer', 'exists:party,pid'],
            'payby' => ['required', 'integer', 'in:0,1'],
            'refno' => ['nullable', 'string', 'max:100'],
        ]);

        if (empty($validated['receipt_no'])) {
            $validated['receipt_no'] = (int) Expense::query()
                    ->where(function ($q) {
                        $q->whereNull('isdel')->orWhere('isdel', '!=', 1);
                    })
                    ->max('receipt_no') + 1;
        }

        $expense = Expense::create($validated);

        return response()->json([
            'message' => 'Expense created successfully',
            'data' => $expense,
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
            'igst' => ['nullable', 'integer'],
            'cgst' => ['nullable', 'integer'],
            'sgst' => ['nullable', 'integer'],
            'dt' => ['nullable', 'date'],
            'party' => ['nullable', 'integer', 'exists:party,pid'],
            'payby' => ['sometimes', 'integer', 'in:0,1'],
            'refno' => ['nullable', 'string', 'max:100'],
        ]);

        $expense->update($validated);

        return response()->json([
            'message' => 'Expense updated successfully',
            'data' => $expense->fresh(['expensesHead', 'partyRelation']),
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
     * Fetch all expenses.
     */
    public function index(): JsonResponse
    {
        $expenses = Expense::with(['expensesHead', 'partyRelation'])->where(function ($q) {
            $q->whereNull('isdel')->orWhere('isdel', '!=', 1);
        })->get();

        return response()->json([
            'message' => 'Expenses fetched successfully',
            'data' => $expenses,
        ], 200);
    }
}

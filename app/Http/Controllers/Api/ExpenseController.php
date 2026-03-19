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
            'igst' => ['nullable', 'integer'],
            'cgst' => ['nullable', 'integer'],
            'sgst' => ['nullable', 'integer'],
            'dt' => ['nullable', 'date'],
            'party' => ['nullable', 'integer', 'exists:party,pid'],
            'payby' => ['required', 'integer', 'in:0,1'],
            'refno' => ['nullable', 'string', 'max:100'],
        ]);

        $expense = Expense::create($validated);

        return response()->json([
            'message' => 'Expense created successfully',
            'data' => $expense,
        ], 201);
    }

    /**
     * Fetch all expenses.
     */
    public function index(): JsonResponse
    {
        $expenses = Expense::with(['expensesHead', 'partyRelation'])->get();

        return response()->json([
            'message' => 'Expenses fetched successfully',
            'data' => $expenses,
        ], 200);
    }
}

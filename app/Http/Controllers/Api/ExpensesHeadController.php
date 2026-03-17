<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExpensesHead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpensesHeadController extends Controller
{
    /**
     * Create a new expenses_head record.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'integer', 'in:0,1'],
        ]);

        $expensesHead = ExpensesHead::create($validated);

        return response()->json([
            'message' => 'Expenses head created successfully',
            'data' => $expensesHead,
        ], 201);
    }

    /**
     * Fetch all expenses_head records.
     */
    public function index(): JsonResponse
    {
        $expensesHeads = ExpensesHead::all();

        return response()->json([
            'message' => 'Expenses heads fetched successfully',
            'data' => $expensesHeads,
        ], 200);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
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
        $paymentByHead = Expense::query()
            ->selectRaw('exhid, COALESCE(SUM(payment), 0) as total_payment')
            ->where(fn ($q) => $q->whereNull('isdel')->orWhere('isdel', '!=', 1))
            ->groupBy('exhid')
            ->pluck('total_payment', 'exhid');

        $expensesHeads = ExpensesHead::all()->map(function (ExpensesHead $head) use ($paymentByHead) {
            $row = $head->toArray();
            $row['payment'] = (float) ($paymentByHead[$head->exhid] ?? 0);

            return $row;
        });

        return response()->json([
            'message' => 'Expenses heads fetched successfully',
            'data' => $expensesHeads,
        ], 200);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayBy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayByController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'integer', 'in:0,1'],
            'name' => ['required', 'string', 'max:255'],
            'detail' => ['nullable', 'string', 'max:500'],
            'prebalance' => ['nullable', 'numeric'],
            'balance' => ['nullable', 'numeric'],
        ]);

        $payBy = PayBy::create($validated);

        return response()->json([
            'message' => 'PayBy created successfully',
            'data' => $payBy,
        ], 201);
    }

    /**
     * Fetch all pay_by records.
     */
    public function index(): JsonResponse
    {
        $payByList = PayBy::all();

        return response()->json([
            'message' => 'PayBy list fetched successfully',
            'data' => $payByList,
        ], 200);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseHead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseHeadController extends Controller
{
    /**
     * Create a new purchase_head record.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'integer', 'in:0,1'],
        ]);

        $purchaseHead = PurchaseHead::create($validated);

        return response()->json([
            'message' => 'Purchase head created successfully',
            'data' => $purchaseHead,
        ], 201);
    }

    /**
     * Fetch all purchase_head records.
     */
    public function index(): JsonResponse
    {
        $purchaseHeads = PurchaseHead::all();

        return response()->json([
            'message' => 'Purchase heads fetched successfully',
            'data' => $purchaseHeads,
        ], 200);
    }
}

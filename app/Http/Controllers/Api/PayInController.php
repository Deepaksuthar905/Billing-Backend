<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayIn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayInController extends Controller
{
    /**
     * Create a new pay_in record.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'party_id' => ['required', 'integer', 'exists:party,pid'],
            'inv_id' => ['required', 'integer', 'exists:invoice,invid'],
            'dt' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
            'payby' => ['nullable', 'integer', 'exists:pay_by,pbid'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'referal' => ['nullable', 'string', 'max:100'],
        ]);

        $payIn = PayIn::create($validated);

        return response()->json([
            'message' => 'PayIn created successfully',
            'data' => $payIn,
        ], 201);
    }

    /**
     * Fetch all pay_in records.
     */
    public function index(): JsonResponse
    {
        $payIns = PayIn::with(['party', 'invoice', 'payBy'])
            ->where('isdel', '!=', 1)
            ->get();

        return response()->json([
            'message' => 'PayIn list fetched successfully',
            'data' => $payIns,
        ], 200);
    }

    // delete the pay_in record means set isdel to 1
    public function delpay_in($pinid): JsonResponse
    {
        $payIn = PayIn::find($pinid);
        if (! $payIn) {
            return response()->json([
                'message' => 'PayIn not found',
            ], 404);
        }

        // `isdel` is not fillable; set directly and save.
        $payIn->isdel = 1;
        $payIn->save();

        return response()->json([
            'message' => 'PayIn deleted successfully',
            'data' => $payIn,
        ], 200);
    }
}

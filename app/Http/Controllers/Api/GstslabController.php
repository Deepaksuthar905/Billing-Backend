<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GstSlab;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GstslabController extends Controller
{
    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'igst' => ['required', 'numeric', 'min:0', 'max:100'],
            'cgst' => ['required', 'numeric', 'min:0', 'max:100'],
            'sgst' => ['required', 'numeric', 'min:0', 'max:100'],
            'label' => ['required', 'string', 'max:255'],
        ]);

        $gstSlab = GstSlab::create($validated);
        return response()->json([
            'message' => 'Gst slab created successfully',
            'data' => $gstSlab,
        ], 201);  
    }

    public function fetchAll(): JsonResponse
    {
        $gstSlabs = GstSlab::all();
        return response()->json([
            'message' => 'Gst slabs fetched successfully',
            'data' => $gstSlabs,
        ], 200);
    }
}   
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item_name' => ['required', 'string', 'max:255'],
            'hsncode' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'rate' => ['required', 'numeric', 'min:0'],
            'with_without' => ['required', 'integer', 'in:0,1'],
            'gst' => ['nullable', 'numeric', 'min:0'],
            'gst_amt' => ['nullable', 'numeric', 'min:0'],
        ]);

        $item = Item::create($validated);

        return response()->json([
            'message' => 'Item created successfully',
            'data' => $item,
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'item_name' => ['required', 'string', 'max:255'],
            'hsncode' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'rate' => ['required', 'numeric', 'min:0'],
            'with_without' => ['required', 'integer', 'in:0,1'],
            'gst' => ['nullable', 'numeric', 'min:0'],
            'gst_amt' => ['nullable', 'numeric', 'min:0'],
        ]);

        $item = Item::find($id);
        $item->update($validated);

        return response()->json([
            'message' => 'Item updated successfully',
            'data' => $item,
        ], 200);
    }


    /**
     * Items list. Query: search=, status= (optional filter).
     * Response: data: [], summary?: { totalItems, lowStockCount, outOfStockCount }
     */
    public function index(Request $request): JsonResponse
    {
        $query = Item::query();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('item_name', 'like', "%{$search}%")
                    ->orWhere('hsncode', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $items = $query->get();
        $totalItems = $items->count();
        $lowStockCount = 0;
        $outOfStockCount = 0;
        // If you add stock column later, set lowStockCount/outOfStockCount here

        $response = [
            'data' => $items,
            'summary' => [
                'totalItems' => $totalItems,
                'lowStockCount' => $lowStockCount,
                'outOfStockCount' => $outOfStockCount,
            ],
        ];

        return response()->json($response, 200);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InvoiceItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceItemController extends Controller
{
    /**
     * Create a new invoice_item record.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'inv_id' => ['required', 'integer', 'exists:invoice,invid'],
            'item_id' => ['required', 'integer', 'exists:items,item_id'],
            'hsnocde' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'rate' => ['required', 'numeric', 'min:0'],
            'qty' => ['required', 'numeric', 'min:0'],
            'payment' => ['nullable', 'numeric', 'min:0'],
            'with_without' => ['required', 'integer', 'in:0,1'],
            'gst' => ['nullable', 'numeric', 'min:0'],
            'gst_amt' => ['nullable', 'numeric', 'min:0'],
        ]);

        $invoiceItem = InvoiceItem::create($validated);

        return response()->json([
            'message' => 'Invoice item created successfully',
            'data' => $invoiceItem,
        ], 201);
    }

    /**
     * Fetch all invoice_item records.
     */
    public function index(): JsonResponse
    {
        $invoiceItems = InvoiceItem::with(['invoice', 'item'])->get();

        return response()->json([
            'message' => 'Invoice items fetched successfully',
            'data' => $invoiceItems,
        ], 200);
    }
}

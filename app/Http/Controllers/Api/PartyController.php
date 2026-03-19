<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Party;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartyController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'partyname' => ['required', 'string', 'max:255'],
            'mobno' => ['nullable', 'string', 'max:20'],
            'cid' => ['nullable', 'string', 'max:50'],
            'billing_name' => ['nullable', 'string', 'max:255'],
            'gst_no' => ['nullable', 'string', 'max:50'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'gst_reg' => ['nullable', 'boolean'],
            'same_state' => ['nullable', 'boolean'],
            'prtytyp' => ['nullable', 'integer', 'in:0,1'],
        ]);

        // Normalize 0/1 to boolean if sent as integer
        if (isset($validated['gst_reg'])) {
            $validated['gst_reg'] = (bool) $validated['gst_reg'];
        }
        if (isset($validated['same_state'])) {
            $validated['same_state'] = (bool) $validated['same_state'];
        }

        $party = Party::create($validated);

        return response()->json([
            'message' => 'Party created successfully',
            'data' => $party,
        ], 201);
    }

    //fetch all parties
    public function index(Request $request): JsonResponse
    {
        $query = Party::query();

        if ($request->filled('prtytyp')) {
            $validated = $request->validate([
                'prtytyp' => ['integer', 'in:0,1'],
            ]);
            $query->where('prtytyp', (int) $validated['prtytyp']);
        }

        $parties = $query->get();
        return response()->json([
            'message' => 'Parties fetched successfully',
            'data' => $parties,
        ], 200);
    }
}

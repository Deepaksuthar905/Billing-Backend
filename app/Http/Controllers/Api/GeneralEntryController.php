<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class GeneralEntryController extends Controller
{
    /**
     * Insert journal_entry and update pay_by balances:
     * from_acc (e.g. Cash) −amt, to_acc (e.g. Drawing) +amt.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
            'from_acc' => ['required', 'integer', 'exists:pay_by,pbid'],
            'to_acc' => ['required', 'integer', 'exists:pay_by,pbid', 'different:from_acc'],
            'amt' => ['required', 'numeric', 'min:0.01'],
            'detail' => ['nullable', 'string'],
        ]);

        $fromAcc = (int) $validated['from_acc'];
        $toAcc = (int) $validated['to_acc'];
        $amt = (float) $validated['amt'];

        try {
            DB::beginTransaction();

            $jid = (int) DB::table('journal_entry')->max('jid') + 1;

            DB::table('journal_entry')->insert([
                'jid' => $jid,
                'dt' => $validated['date'],
                'from_acc' => $fromAcc,
                'to_acc' => $toAcc,
                'amt' => $amt,
                'detail' => $validated['detail'] ?? null,
                'isdel' => 0,
            ]);

            DB::table('pay_by')->where('pbid', $fromAcc)->decrement('balance', $amt);
            DB::table('pay_by')->where('pbid', $toAcc)->increment('balance', $amt);

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Journal entry failed',
                'error' => config('app.debug') ? $e->getMessage() : 'Could not save entry',
            ], 500);
        }

        $row = DB::table('journal_entry')->where('jid', $jid)->first();
        if ($row) {
            $row->date = $row->dt;
        }

        $fromBalance = DB::table('pay_by')->where('pbid', $fromAcc)->value('balance');
        $toBalance = DB::table('pay_by')->where('pbid', $toAcc)->value('balance');

        return response()->json([
            'message' => 'Journal entry created successfully',
            'data' => $row,
            'balances' => [
                'from_acc' => ['pbid' => $fromAcc, 'balance' => (float) $fromBalance],
                'to_acc' => ['pbid' => $toAcc, 'balance' => (float) $toBalance],
            ],
        ], 201);
    }

    /**
     * Fetch journal_entry list (not deleted).
     */
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('journal_entry')
            ->where('isdel', '!=', 1)
            ->orderByDesc('jid');

        if ($from = $request->query('from')) {
            $to = $request->query('to', $from);
            $query->whereBetween('dt', [$from, $to]);
        }

        $rows = $query->get()->map(function ($row) {
            $row->date = $row->dt;

            return $row;
        });

        return response()->json([
            'message' => 'Journal entries fetched successfully',
            'data' => $rows,
        ], 200);
    }
}

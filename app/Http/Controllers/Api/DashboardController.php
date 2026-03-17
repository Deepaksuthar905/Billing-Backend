<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Purchase;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * Dashboard summary: totalSales, totalPurchase, outstanding, invoiceCount, recentInvoices, recentPurchases.
     */
    public function index(): JsonResponse
    {
        $totalSales = (float) Invoice::sum('payment');
        $totalPurchase = (float) Purchase::sum('payment');
        $outstanding = (float) Invoice::sum('balance');
        $invoiceCount = Invoice::count();

        $recentInvoices = Invoice::with('party')
            ->orderByDesc('dt')
            ->limit(10)
            ->get()
            ->map(fn ($inv) => [
                'id' => $inv->invid,
                'date' => $inv->dt?->format('Y-m-d'),
                'customer' => $inv->party?->partyname,
                'amount' => (float) $inv->payment,
                'gst' => (float) $inv->gst,
                'status' => ($inv->balance && (float) $inv->balance > 0) ? 'pending' : 'paid',
            ]);

        $recentPurchases = Purchase::with(['party', 'purchaseHead'])
            ->orderByDesc('dt')
            ->limit(10)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->prid,
                'date' => $p->dt?->format('Y-m-d'),
                'vendor' => $p->purchaseHead?->name ?? $p->party?->partyname,
                'amount' => (float) $p->payment,
                'status' => 'completed',
            ]);

        return response()->json([
            'totalSales' => $totalSales,
            'totalPurchase' => $totalPurchase,
            'outstanding' => $outstanding,
            'invoiceCount' => $invoiceCount,
            'recentInvoices' => $recentInvoices,
            'recentPurchases' => $recentPurchases,
        ], 200);
    }
}

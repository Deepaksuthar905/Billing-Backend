<?php

use App\Http\Controllers\Api\PartyController;
use App\Http\Controllers\Api\GstslabController;
use App\Http\Controllers\Api\PayByController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PayInController;
use App\Http\Controllers\Api\InvoiceItemController;
use App\Http\Controllers\Api\ExpensesHeadController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\DashboardController;
// use App\Http\Controllers\Api\CustomerController;
// use App\Http\Controllers\Api\VendorController;
use App\Http\Controllers\Api\SalesController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| API Routes (sab yahi web.php mein)
|--------------------------------------------------------------------------
*/
Route::prefix('api')->group(function () {

    // ----- Frontend APIs (documentation table) -----
    Route::get('/dashboard', [DashboardController::class, 'index']);
    // Route::get('/invoices', [InvoiceController::class, 'index']);
    // Route::post('/invoices', [InvoiceController::class, 'store']);
    Route::get('/sales', [SalesController::class, 'index']);
    // Route::get('/purchase-orders', [PurchaseController::class, 'index']);
    // Route::get('/vendors', [VendorController::class, 'index']);
    Route::get('/customers', [PartyController::class, 'index']);
    Route::get('/items', [ItemController::class, 'index']);

    Route::post('/parties', [PartyController::class, 'store']);
    Route::get('/gstslabs', [GstslabController::class, 'fetchAll']);
    Route::post('/gstslabs', [GstslabController::class, 'create']);
    Route::post('/pay-by', [PayByController::class, 'store']);
    Route::get('/pay-by', [PayByController::class, 'index']);
    Route::post('/items', [ItemController::class, 'store']);
    Route::get('/items', [ItemController::class, 'index']);
    Route::post('/invoices', [InvoiceController::class, 'store']);
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::post('/pay-in', [PayInController::class, 'store']);
    Route::get('/pay-in', [PayInController::class, 'index']);
    Route::post('/invoice-items', [InvoiceItemController::class, 'store']);
    Route::get('/invoice-items', [InvoiceItemController::class, 'index']);
    Route::post('/expenses-heads', [ExpensesHeadController::class, 'store']);
    Route::get('/expenses-heads', [ExpensesHeadController::class, 'index']);
    Route::post('/expenses', [ExpenseController::class, 'store']);
    Route::get('/expenses', [ExpenseController::class, 'index']);
    Route::post('/purchases', [PurchaseController::class, 'store']);
    Route::get('/purchases', [PurchaseController::class, 'index']);
    Route::put('/items/{id}', [ItemController::class, 'update']);
    Route::post('/sync-invoices', [InvoiceController::class, 'sync']);
    Route::get('/gstratereport', [PurchaseController::class, 'gstratereport']);
});

<?php

use App\Http\Controllers\Api\V1Controller;
use Illuminate\Support\Facades\Route;

Route::middleware(['api.token'])->prefix('v1')->group(function () {
    Route::get('/items', [V1Controller::class, 'items']);
    Route::get('/stock', [V1Controller::class, 'stock']);
    Route::get('/customers', [V1Controller::class, 'customers']);
    Route::get('/sales/{sale}', [V1Controller::class, 'sale']);
    Route::post('/sales', [V1Controller::class, 'storeSale']);
    Route::get('/production-orders', [V1Controller::class, 'productionOrders']);
    Route::post('/production-orders', [V1Controller::class, 'storeProductionOrder']);
});

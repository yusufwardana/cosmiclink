<?php

use App\Http\Controllers\AgentApiController;
use App\Http\Controllers\Api\V1\ApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['web', 'auth'])->group(function () {
    Route::get('/dashboard', [ApiController::class, 'dashboard'])->name('api.v1.dashboard');
    Route::get('/customers', [ApiController::class, 'customers'])->name('api.v1.customers.index');
    Route::get('/customers/{customer}', [ApiController::class, 'customer'])->name('api.v1.customers.show');
    Route::get('/monitoring', [ApiController::class, 'monitoring'])->name('api.v1.monitoring.index');
    Route::post('/monitoring/check', [ApiController::class, 'check'])->name('api.v1.monitoring.check');
    Route::post('/monitoring/routers/{router}/simulation', [ApiController::class, 'simulateRouter'])->name('api.v1.monitoring.routers.simulation');
    Route::post('/monitoring/connections/{connection}/simulation', [ApiController::class, 'simulateConnection'])->name('api.v1.monitoring.connections.simulation');
    Route::get('/outages', [ApiController::class, 'outages'])->name('api.v1.outages.index');
    Route::get('/outages/{incident}', [ApiController::class, 'outage'])->name('api.v1.outages.show');
    Route::post('/outages/{incident}/acknowledge', [ApiController::class, 'acknowledge'])->name('api.v1.outages.acknowledge');
});

Route::prefix('v1/agent')->group(function () {
    Route::post('/heartbeat', [AgentApiController::class, 'heartbeat']);
    Route::post('/jobs/claim', [AgentApiController::class, 'claim']);
    Route::post('/jobs/{job}/result', [AgentApiController::class, 'result']);
    Route::post('/jobs/{job}/renew', [AgentApiController::class, 'renew']);
});

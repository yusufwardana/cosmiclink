<?php

use App\Http\Controllers\AgentApiController;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\NetworkTopologyController;
use App\Http\Controllers\Api\V1\NetworkMapController;
use App\Http\Controllers\Api\V1\TrafficAnalyticsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['web', 'auth'])->group(function () {
    Route::get('/dashboard', [ApiController::class, 'dashboard'])->name('api.v1.dashboard');
    Route::get('/topology', NetworkTopologyController::class)->name('api.v1.topology');
    Route::get('/network-map', [NetworkMapController::class, 'index'])->name('api.v1.network-map.index');
    Route::put('/network-map/routers/{router}/location', [NetworkMapController::class, 'updateLocation'])->name('api.v1.network-map.routers.location');
    Route::put('/network-map/customers/{customer}/location', [NetworkMapController::class, 'updateCustomerLocation'])->name('api.v1.network-map.customers.location');
    Route::get('/customers', [ApiController::class, 'customers'])->name('api.v1.customers.index');
    Route::get('/customers/{customer}', [ApiController::class, 'customer'])->name('api.v1.customers.show');
    Route::get('/monitoring', [ApiController::class, 'monitoring'])->name('api.v1.monitoring.index');
    Route::get('/reconciliation', [ApiController::class, 'reconciliation'])->name('api.v1.reconciliation.index');
    Route::post('/reconciliation/{resource}/adopt', [ApiController::class, 'adoptReconciliation'])->name('api.v1.reconciliation.adopt');
    Route::post('/reconciliation/{resource}/unadopt', [ApiController::class, 'unadoptReconciliation'])->name('api.v1.reconciliation.unadopt');
    Route::post('/monitoring/check', [ApiController::class, 'check'])->name('api.v1.monitoring.check');
    Route::post('/monitoring/routers/{router}/simulation', [ApiController::class, 'simulateRouter'])->name('api.v1.monitoring.routers.simulation');
    Route::post('/monitoring/connections/{connection}/simulation', [ApiController::class, 'simulateConnection'])->name('api.v1.monitoring.connections.simulation');
    Route::get('/traffic/overview', [TrafficAnalyticsController::class, 'overview'])->name('api.v1.traffic.overview');
    Route::get('/traffic/rankings', [TrafficAnalyticsController::class, 'rankings'])->name('api.v1.traffic.rankings');
    Route::get('/traffic/subscriber-history', [TrafficAnalyticsController::class, 'subscriberHistory'])->name('api.v1.traffic.subscriber-history');
    Route::get('/traffic/interface-history', [TrafficAnalyticsController::class, 'interfaceHistory'])->name('api.v1.traffic.interface-history');
    Route::get('/traffic/peak-hours', [TrafficAnalyticsController::class, 'peakHours'])->name('api.v1.traffic.peak-hours');
    Route::get('/outages', [ApiController::class, 'outages'])->name('api.v1.outages.index');
    Route::get('/outages/{incident}', [ApiController::class, 'outage'])->name('api.v1.outages.show');
    Route::post('/outages/{incident}/acknowledge', [ApiController::class, 'acknowledge'])->name('api.v1.outages.acknowledge');
});

Route::prefix('v1/agent')->group(function () {
    Route::post('/heartbeat', [AgentApiController::class, 'heartbeat']);
    Route::post('/jobs/claim', [AgentApiController::class, 'claim']);
    Route::post('/jobs/{job}/result', [AgentApiController::class, 'result']);
    Route::post('/jobs/{job}/renew', [AgentApiController::class, 'renew']);
    Route::post('/observer-references/sync', [AgentApiController::class, 'syncObserverReference']);
});

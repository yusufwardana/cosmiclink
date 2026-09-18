<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CustomerConnectionController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InternetPackageController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\NetworkAccountController;
use App\Http\Controllers\NetworkAgentController;
use App\Http\Controllers\NetworkDiscoveryController;
use App\Http\Controllers\OperationLogController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentRequestController;
use App\Http\Controllers\RouterController;
use App\Models\NetworkAccount;
use App\Services\Network\NetworkOperationService;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : redirect()->route('login'));
Route::get('/login', [AuthController::class, 'create'])->name('login');
Route::post('/login', [AuthController::class, 'store'])->name('login.store');
Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/billing/invoices', [BillingController::class, 'index'])->name('billing.invoices.index');
    Route::get('/billing/invoices/{invoice}', [BillingController::class, 'show'])->name('billing.invoices.show');
    Route::post('/billing/generate', [BillingController::class, 'generate'])->name('billing.generate');
    Route::post('/billing/overdue', [BillingController::class, 'overdue'])->name('billing.overdue');
    Route::get('/billing/payments', [PaymentController::class, 'index'])->name('billing.payments.index');
    Route::post('/billing/invoices/{invoice}/payments', [PaymentController::class, 'store'])->name('billing.payments.store');
    Route::post('/billing/invoices/{invoice}/payment-request', [BillingController::class, 'paymentRequest'])->name('billing.payment-requests.store');
    Route::post('/billing/invoices/{invoice}/reminder', [BillingController::class, 'reminder'])->name('billing.reminders.store');
    Route::get('/billing/payment-requests/{paymentRequest}', [PaymentRequestController::class, 'show'])->name('billing.payment-requests.show');
    Route::post('/billing/payment-requests/{paymentRequest}/simulate-success', [PaymentRequestController::class, 'simulateSuccess'])->name('billing.payment-requests.simulate-success');
    Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
    Route::get('/monitoring', [MonitoringController::class, 'index'])->name('monitoring.index');
    Route::post('/monitoring/check', [MonitoringController::class, 'check'])->name('monitoring.check');
    Route::post('/monitoring/routers/{router}/observe', [MonitoringController::class, 'observeRouter'])->name('monitoring.routers.observe');
    Route::post('/monitoring/connections/{connection}/observe', [MonitoringController::class, 'observeConnection'])->name('monitoring.connections.observe');
    Route::post('/monitoring/routers/{router}/simulation', [MonitoringController::class, 'routerSimulation'])->name('monitoring.routers.simulation');
    Route::post('/monitoring/connections/{connection}/simulation', [MonitoringController::class, 'connectionSimulation'])->name('monitoring.connections.simulation');
    Route::get('/monitoring/history/{type}/{id}', [MonitoringController::class, 'history'])->name('monitoring.history');
    Route::get('/monitoring/incidents/{incident}', [MonitoringController::class, 'incident'])->name('monitoring.incidents.show');
    Route::post('/monitoring/incidents/{incident}/acknowledge', [MonitoringController::class, 'acknowledge'])->name('monitoring.incidents.acknowledge');
    Route::resource('customers', CustomerController::class)->except(['destroy']);
    Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');
    Route::resource('packages', InternetPackageController::class)->except(['destroy']);
    Route::delete('/packages/{package}', [InternetPackageController::class, 'destroy'])->name('packages.destroy');
    Route::post('/packages/{package}/{status}', [InternetPackageController::class, 'status'])->name('packages.status');
    Route::get('/customers/{customer}/connections/create', [CustomerConnectionController::class, 'create'])->name('customers.connections.create');
    Route::post('/customers/{customer}/connections', [CustomerConnectionController::class, 'store'])->name('customers.connections.store');
    Route::post('/connections/{connection}/provision', [CustomerConnectionController::class, 'provision'])->name('connections.provision');
    // The explicit create route must be declared before the resource: the
    // resource's `routers/{router}` would otherwise capture "create" as a
    // router id and the page's Add router action would 500.
    Route::get('/routers/create', [RouterController::class, 'create'])->name('routers.create');
    Route::resource('routers', RouterController::class)->except(['create']);
    Route::post('/routers/{router}/test', [RouterController::class, 'test'])->name('routers.test');
    Route::get('/network/accounts', [NetworkAccountController::class, 'index'])->name('network.accounts.index');
    Route::get('/network/agents', [NetworkAgentController::class, 'index'])->name('network.agents.index');
    Route::get('/network/agents/{agent}', [NetworkAgentController::class, 'show'])->name('network.agents.show');
    Route::get('/network/discovery', [NetworkDiscoveryController::class, 'index'])->name('network.discovery.index');
    Route::post('/network/discovery/routers/{router}', [NetworkDiscoveryController::class, 'discover'])->name('network.discovery.run');
    Route::post('/network/discovery/resources/{resource}/adopt', [NetworkDiscoveryController::class, 'adopt'])->name('network.discovery.adopt');
    Route::post('/network/discovery/resources/{resource}/unadopt', [NetworkDiscoveryController::class, 'unadopt'])->name('network.discovery.unadopt');
    Route::post('/network/accounts', [NetworkAccountController::class, 'store'])->name('network.accounts.store');
    Route::post('/network/accounts/{account}/disable', fn (NetworkAccount $account, NetworkAccountController $controller, NetworkOperationService $operations) => $controller->status($account, 'disabled', $operations))->name('network.accounts.disable');
    Route::post('/network/accounts/{account}/enable', fn (NetworkAccount $account, NetworkAccountController $controller, NetworkOperationService $operations) => $controller->status($account, 'active', $operations))->name('network.accounts.enable');
    Route::put('/network/accounts/{account}/profile', [NetworkAccountController::class, 'profile'])->name('network.accounts.profile');
    Route::post('/network/accounts/{account}/disconnect', [NetworkAccountController::class, 'disconnect'])->name('network.accounts.disconnect');
    Route::post('/network/accounts/{account}/{status}', [NetworkAccountController::class, 'status'])->name('network.accounts.status');
    Route::get('/network/logs', [OperationLogController::class, 'index'])->name('network.logs.index');
});

<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerConnectionController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InternetPackageController;
use App\Http\Controllers\NetworkAccountController;
use App\Http\Controllers\OperationLogController;
use App\Http\Controllers\RouterController;
use App\Models\NetworkAccount;
use App\Services\Network\NetworkOperationService;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : view('welcome'));
Route::get('/login', [AuthController::class, 'create'])->name('login');
Route::post('/login', [AuthController::class, 'store'])->name('login.store');
Route::post('/logout', [AuthController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::resource('customers', CustomerController::class)->except(['destroy']);
    Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');
    Route::resource('packages', InternetPackageController::class)->except(['destroy']);
    Route::delete('/packages/{package}', [InternetPackageController::class, 'destroy'])->name('packages.destroy');
    Route::post('/packages/{package}/{status}', [InternetPackageController::class, 'status'])->name('packages.status');
    Route::get('/customers/{customer}/connections/create', [CustomerConnectionController::class, 'create'])->name('customers.connections.create');
    Route::post('/customers/{customer}/connections', [CustomerConnectionController::class, 'store'])->name('customers.connections.store');
    Route::post('/connections/{connection}/provision', [CustomerConnectionController::class, 'provision'])->name('connections.provision');
    Route::resource('routers', RouterController::class)->except(['create']);
    Route::get('/routers/create', [RouterController::class, 'create'])->name('routers.create');
    Route::post('/routers/{router}/test', [RouterController::class, 'test'])->name('routers.test');
    Route::get('/network/accounts', [NetworkAccountController::class, 'index'])->name('network.accounts.index');
    Route::post('/network/accounts', [NetworkAccountController::class, 'store'])->name('network.accounts.store');
    Route::post('/network/accounts/{account}/disable', fn (NetworkAccount $account, NetworkAccountController $controller, NetworkOperationService $operations) => $controller->status($account, 'disabled', $operations))->name('network.accounts.disable');
    Route::post('/network/accounts/{account}/enable', fn (NetworkAccount $account, NetworkAccountController $controller, NetworkOperationService $operations) => $controller->status($account, 'active', $operations))->name('network.accounts.enable');
    Route::put('/network/accounts/{account}/profile', [NetworkAccountController::class, 'profile'])->name('network.accounts.profile');
    Route::post('/network/accounts/{account}/disconnect', [NetworkAccountController::class, 'disconnect'])->name('network.accounts.disconnect');
    Route::post('/network/accounts/{account}/{status}', [NetworkAccountController::class, 'status'])->name('network.accounts.status');
    Route::get('/network/logs', [OperationLogController::class, 'index'])->name('network.logs.index');
});

<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\NetworkAccount;
use App\Models\NetworkOperationLog;
use App\Models\Router;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $tenantId = Auth::user()->tenant_id;

        return view('dashboard', [
            'routerCount' => Router::where('tenant_id', $tenantId)->count(),
            'customerCount' => Customer::where('tenant_id', $tenantId)->count(),
            'activeCustomers' => Customer::where('tenant_id', $tenantId)->where('status', 'active')->count(),
            'connectionCount' => CustomerConnection::where('tenant_id', $tenantId)->count(),
            'activeConnections' => CustomerConnection::where('tenant_id', $tenantId)->where('status', 'active')->count(),
            'pendingConnections' => CustomerConnection::where('tenant_id', $tenantId)->where('status', 'pending')->count(),
            'failedConnections' => CustomerConnection::where('tenant_id', $tenantId)->where('status', 'failed')->count(),
            'onlineRouters' => Router::where('tenant_id', $tenantId)->where('status', 'available')->count(),
            'accountCount' => NetworkAccount::where('tenant_id', $tenantId)->count(),
            'activeAccounts' => NetworkAccount::where('tenant_id', $tenantId)->where('status', 'active')->count(),
            'disabledAccounts' => NetworkAccount::where('tenant_id', $tenantId)->where('status', 'disabled')->count(),
            'recentLogs' => NetworkOperationLog::where('tenant_id', $tenantId)->latest('created_at')->limit(10)->get(),
        ]);
    }
}

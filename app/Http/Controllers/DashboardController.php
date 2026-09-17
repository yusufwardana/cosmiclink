<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\HealthObservation;
use App\Models\Invoice;
use App\Models\NetworkAccount;
use App\Models\NetworkOperationLog;
use App\Models\OutageIncident;
use App\Models\Payment;
use App\Models\Router;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $tenantId = Auth::user()->tenant_id;

        $routerCount = Router::where('tenant_id', $tenantId)->count();
        $latestRouterHealth = HealthObservation::where('tenant_id', $tenantId)
            ->where('subject_type', 'router')
            ->whereIn('subject_id', Router::where('tenant_id', $tenantId)->pluck('id'))
            ->latest('observed_at')
            ->get()
            ->unique('subject_id');
        $routerHealth = ['online' => 0, 'degraded' => 0, 'offline' => 0, 'unknown' => max($routerCount - $latestRouterHealth->count(), 0)];
        foreach ($latestRouterHealth as $observation) {
            $routerHealth[$observation->health_state] = ($routerHealth[$observation->health_state] ?? 0) + 1;
        }

        return view('dashboard', [
            'routerCount' => $routerCount,
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
            'unpaidInvoices' => Invoice::where('tenant_id', $tenantId)->where('status', 'unpaid')->count(),
            'overdueInvoices' => Invoice::where('tenant_id', $tenantId)->where('status', 'overdue')->count(),
            'outstandingAmount' => Invoice::where('tenant_id', $tenantId)->whereIn('status', ['unpaid', 'overdue'])->sum('total') - Invoice::where('tenant_id', $tenantId)->whereIn('status', ['unpaid', 'overdue'])->sum('paid_amount'),
            'billingSuspendedConnections' => CustomerConnection::where('tenant_id', $tenantId)->where('status', 'suspended')->where('suspension_reason', 'billing_overdue')->count(),
            'routerHealth' => $routerHealth,
            'routerHealthObserved' => $latestRouterHealth->count(),
            'latestObservationAt' => HealthObservation::where('tenant_id', $tenantId)->max('observed_at'),
            'activeIncidents' => OutageIncident::where('tenant_id', $tenantId)->whereIn('status', ['detected', 'acknowledged'])->with('router')->withCount('affectedConnections')->latest('detected_at')->limit(4)->get(),
            'activeIncidentCount' => OutageIncident::where('tenant_id', $tenantId)->whereIn('status', ['detected', 'acknowledged'])->count(),
            'recentPayments' => Payment::where('tenant_id', $tenantId)->latest('paid_at')->limit(5)->get(),
            'recentLogs' => NetworkOperationLog::where('tenant_id', $tenantId)->latest('created_at')->limit(10)->get(),
        ]);
    }
}

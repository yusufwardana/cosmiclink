<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\InternetPackage;
use App\Models\Router;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

class TrafficIntelligenceController extends Controller
{
    public function __invoke(): View
    {
        $tenantId = Auth::user()->tenant_id;

        $filters = [
            'routers' => Router::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('name')
                ->get(['id', 'name']),
            'customers' => Customer::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('name')
                ->get(['id', 'name']),
            'connections' => CustomerConnection::query()
                ->where('tenant_id', $tenantId)
                ->with([
                    'customer:id,name',
                    'internetPackage:id,name',
                    'networkAccount:id,username',
                    'router:id,name',
                ])
                ->orderBy('id')
                ->get(['id', 'connection_code', 'customer_id', 'internet_package_id', 'network_account_id', 'router_id'])
                ->map(fn (CustomerConnection $connection) => [
                    'id' => $connection->id,
                    'code' => $connection->connection_code,
                    'customer_id' => $connection->customer_id,
                    'customer_name' => $connection->customer?->name,
                    'package_id' => $connection->internet_package_id,
                    'package_name' => $connection->internetPackage?->name,
                    'router_id' => $connection->router_id,
                    'router_name' => $connection->router?->name,
                    'identity' => $connection->networkAccount?->username,
                ]),
            'packages' => InternetPackage::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('name')
                ->get(['id', 'name']),
        ];

        return view('traffic.index', compact('filters'));
    }
}

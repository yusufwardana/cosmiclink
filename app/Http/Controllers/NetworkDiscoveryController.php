<?php

namespace App\Http\Controllers;

use App\Models\CustomerConnection;
use App\Models\DiscoveredNetworkResource;
use App\Models\Router;
use App\Services\Network\AdoptDiscoveredNetworkResource;
use App\Services\Network\NetworkDiscoveryService;
use App\Services\Network\NetworkReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class NetworkDiscoveryController extends Controller
{
    public function index(NetworkReconciliationService $reconciliation)
    {
        $tenant = Auth::user()->tenant_id;
        $routers = Router::where('tenant_id', $tenant)->with('discoverySnapshots')->get();
        $resources = DiscoveredNetworkResource::where('tenant_id', $tenant)->with('router')->latest('last_seen_at')->get();

        $statuses = [];
        foreach ($routers as $router) {
            foreach ($reconciliation->reconcile($router) as $result) {
                $statuses[$result['resource']->id] = $result['status'];
            }
        }

        return view('network.discovery', ['routers' => $routers, 'resources' => $resources, 'reconciliation' => $statuses, 'connections' => CustomerConnection::where('tenant_id', $tenant)->get()]);
    }

    public function discover(Router $router, NetworkDiscoveryService $service)
    {
        Gate::authorize('operate', $router);
        $snapshot = $service->discover($router, Auth::user());

        return back()->with($snapshot->status === 'success' ? 'status' : 'error', $snapshot->status === 'success' ? 'Read-only discovery completed.' : $snapshot->error);
    }

    public function adopt(Request $request, DiscoveredNetworkResource $resource, AdoptDiscoveredNetworkResource $adopt)
    {
        abort_unless($resource->tenant_id === Auth::user()->tenant_id, 403);
        $data = $request->validate(['customer_connection_id' => ['required', 'integer']]);
        $connection = CustomerConnection::where('tenant_id', Auth::user()->tenant_id)->findOrFail($data['customer_connection_id']);
        $adopt->handle($resource, $connection, Auth::user());

        return back()->with('status', 'Existing network resource adopted without router changes.');
    }
}

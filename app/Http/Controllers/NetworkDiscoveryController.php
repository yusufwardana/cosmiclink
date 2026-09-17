<?php

namespace App\Http\Controllers;

use App\Models\CustomerConnection;
use App\Models\DiscoveredNetworkResource;
use App\Models\NetworkAgent;
use App\Models\NetworkAgentJob;
use App\Models\Router;
use App\Services\Network\AdoptDiscoveredNetworkResource;
use App\Services\Network\NetworkAgentService;
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
        $latestDiscovery = $routers->mapWithKeys(function (Router $router) {
            $snapshot = $router->discoverySnapshots->where('status', 'success')->sortByDesc('id')->first();

            return [$router->id => $snapshot ? [
                'device' => array_intersect_key((array) ($snapshot->snapshot['device'] ?? []), array_flip(['name', 'routeros_version', 'architecture', 'board_name', 'platform'])),
                'summary' => (array) $snapshot->summary,
            ] : null];
        });

        $statuses = [];
        foreach ($routers as $router) {
            foreach ($reconciliation->reconcile($router) as $result) {
                $statuses[$result['resource']->id] = $result['status'];
            }
        }

        return view('network.discovery', ['routers' => $routers, 'resources' => $resources, 'reconciliation' => $statuses, 'connections' => CustomerConnection::where('tenant_id', $tenant)->get(), 'latestDiscovery' => $latestDiscovery, 'agents' => NetworkAgent::where('tenant_id', $tenant)->orderBy('name')->get(), 'agentJobs' => NetworkAgentJob::where('tenant_id', $tenant)->with(['agent', 'router'])->latest()->take(20)->get()]);
    }

    public function discover(Request $request, Router $router, NetworkDiscoveryService $service, NetworkAgentService $agents)
    {
        Gate::authorize('operate', $router);
        $data = $request->validate(['network_agent_id' => ['nullable', 'integer']]);
        if (! empty($data['network_agent_id'])) {
            $agent = NetworkAgent::where('tenant_id', Auth::user()->tenant_id)->findOrFail($data['network_agent_id']);
            $job = $agents->createDiscoveryJob($router, $agent, Auth::user());

            return back()->with('status', 'Discovery job '.$job->id.' is PENDING for '.$agent->name.'.');
        }
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

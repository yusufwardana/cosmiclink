<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\MonitoringResource;
use App\Http\Resources\OutageIncidentResource;
use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\HealthObservation;
use App\Models\Invoice;
use App\Models\NetworkAccount;
use App\Models\OutageIncident;
use App\Models\Router;
use App\Services\Monitoring\MonitoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ApiController extends Controller
{
    public function dashboard()
    {
        $tenant = Auth::user()->tenant_id;
        $unpaid = Invoice::where('tenant_id', $tenant)->whereIn('status', ['unpaid', 'overdue']);

        return response()->json(['data' => ['customers' => Customer::where('tenant_id', $tenant)->count(), 'connections' => CustomerConnection::where('tenant_id', $tenant)->where('status', 'active')->count(), 'billing' => ['overdue' => Invoice::where('tenant_id', $tenant)->where('status', 'overdue')->count(), 'outstanding' => (int) ($unpaid->sum('total') - $unpaid->sum('paid_amount'))], 'network' => ['routers' => Router::where('tenant_id', $tenant)->count(), 'accounts' => NetworkAccount::where('tenant_id', $tenant)->where('status', 'active')->count()], 'outages' => OutageIncident::where('tenant_id', $tenant)->whereIn('status', ['detected', 'acknowledged'])->count()]]);
    }

    public function customers()
    {
        return CustomerResource::collection(Customer::where('tenant_id', Auth::user()->tenant_id)->latest()->paginate(min((int) request('per_page', 15), 50)));
    }

    public function customer(Customer $customer)
    {
        Gate::authorize('view', $customer);
        $customer->load(['connections.router', 'connections.networkAccount', 'invoices', 'payments', 'messageLogs']);
        $incidentIds = OutageIncident::where('tenant_id', Auth::user()->tenant_id)->whereHas('affectedConnections', fn ($query) => $query->whereIn('customer_connections.id', $customer->connections->pluck('id')))->pluck('id');

        $customer->setRelation('outage_incidents', OutageIncident::whereIn('id', $incidentIds)->with(['router', 'affectedConnections.customer'])->get());

        return response()->json(['data' => (new CustomerResource($customer))->resolve()]);
    }

    public function monitoring()
    {
        $tenant = Auth::user()->tenant_id;
        $routers = Router::where('tenant_id', $tenant)->get();
        $connections = CustomerConnection::where('tenant_id', $tenant)->whereNotNull('provisioned_at')->with(['router', 'customer'])->get();
        $observations = HealthObservation::where('tenant_id', $tenant)->latest('observed_at')->get()->unique(fn ($item) => $item->subject_type.':'.$item->subject_id);
        $observations->each(function ($observation) {
            $observation->setRelation('subject', $observation->subject);
            if ($observation->subject_type === 'connection') {
                $observation->subject->load(['customer', 'router']);
            }
        });

        return response()->json(['data' => ['summary' => ['routers' => $observations->where('subject_type', 'router')->groupBy('health_state')->map->count(), 'connections' => $observations->where('subject_type', 'connection')->groupBy('health_state')->map->count()], 'routers' => MonitoringResource::collection($observations->where('subject_type', 'router')->values()), 'connections' => MonitoringResource::collection($observations->where('subject_type', 'connection')->values()), 'incidents' => OutageIncidentResource::collection(OutageIncident::where('tenant_id', $tenant)->with(['router', 'affectedConnections.customer'])->latest('detected_at')->get())]]);
    }

    public function check(MonitoringService $monitoring)
    {
        $monitoring->observeTenant(Auth::user()->tenant_id, Auth::user());

        return response()->json(['message' => 'Monitoring check completed.']);
    }

    public function simulateRouter(Request $request, Router $router)
    {
        Gate::authorize('update', $router);
        abort_unless(config('monitoring.simulation'), 403);
        $data = $request->validate(['state' => ['required', 'in:online,degraded,offline']]);
        $router->update(['monitoring_state' => $data['state']]);

        return response()->json(['message' => 'Router simulation state updated.']);
    }

    public function simulateConnection(Request $request, CustomerConnection $connection)
    {
        Gate::authorize('view', $connection);
        abort_unless(config('monitoring.simulation'), 403);
        $data = $request->validate(['state' => ['required', 'in:online,offline,unknown']]);
        $connection->update(['monitoring_state' => $data['state']]);

        return response()->json(['message' => 'Connection simulation state updated.']);
    }

    public function outages()
    {
        return OutageIncidentResource::collection(OutageIncident::where('tenant_id', Auth::user()->tenant_id)->with(['router', 'affectedConnections.customer'])->latest('detected_at')->paginate(min((int) request('per_page', 15), 50)));
    }

    public function outage(OutageIncident $incident)
    {
        Gate::authorize('view', $incident);

        return new OutageIncidentResource($incident->load(['router', 'affectedConnections.customer']));
    }

    public function acknowledge(OutageIncident $incident)
    {
        Gate::authorize('acknowledge', $incident);
        $incident->update(['status' => 'acknowledged', 'acknowledged_at' => now()]);

        return response()->json(['message' => 'Outage incident acknowledged.']);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\CustomerConnection;
use App\Models\HealthObservation;
use App\Models\OutageIncident;
use App\Models\Router;
use App\Services\Monitoring\HealthState;
use App\Services\Monitoring\MonitoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class MonitoringController extends Controller
{
    public function index()
    {
        $tenantId = Auth::user()->tenant_id;
        $routers = Router::where('tenant_id', $tenantId)->get();
        $connections = CustomerConnection::where('tenant_id', $tenantId)->whereNotNull('provisioned_at')->with(['customer', 'router', 'networkAccount'])->get();
        $routerHealth = HealthObservation::where('tenant_id', $tenantId)->where('subject_type', 'router')->whereIn('subject_id', $routers->pluck('id'))->latest('observed_at')->get()->unique('subject_id')->keyBy('subject_id');
        $connectionHealth = HealthObservation::where('tenant_id', $tenantId)->where('subject_type', 'connection')->whereIn('subject_id', $connections->pluck('id'))->latest('observed_at')->get()->unique('subject_id')->keyBy('subject_id');
        $routers->each(fn (Router $router) => $router->setRelation('networkHealth', $routerHealth->get($router->id)));
        $connections->each(fn (CustomerConnection $connection) => $connection->setRelation('networkHealth', $connectionHealth->get($connection->id)));
        $routerHealthSummary = $routers->groupBy(fn (Router $router) => $router->networkHealth?->health_state ?? HealthState::UNKNOWN->value);
        $connectionHealthSummary = $connections->groupBy(fn (CustomerConnection $connection) => $connection->networkHealth?->health_state ?? HealthState::UNKNOWN->value);
        $incidents = OutageIncident::where('tenant_id', $tenantId)->with(['router', 'affectedConnections.customer'])->latest('detected_at')->limit(20)->get();

        return view('monitoring.index', compact('routers', 'connections', 'routerHealthSummary', 'connectionHealthSummary', 'incidents'));
    }

    public function check(MonitoringService $monitoring)
    {
        $monitoring->observeTenant(Auth::user()->tenant_id, Auth::user());

        return back()->with('status', 'Monitoring check completed.');
    }

    public function observeRouter(Router $router, MonitoringService $monitoring)
    {
        Gate::authorize('view', $router);
        $monitoring->observeRouter($router, Auth::user());

        return back()->with('status', 'Router health observed.');
    }

    public function observeConnection(CustomerConnection $connection, MonitoringService $monitoring)
    {
        Gate::authorize('view', $connection);
        $monitoring->observeConnection($connection, Auth::user());

        return back()->with('status', 'Connection health observed.');
    }

    public function routerSimulation(Request $request, Router $router)
    {
        Gate::authorize('update', $router);
        abort_unless(config('monitoring.simulation'), 403);
        $data = $request->validate(['state' => ['required', 'in:online,degraded,offline']]);
        $router->update(['monitoring_state' => $data['state']]);

        return back()->with('status', 'Router simulation state updated.');
    }

    public function connectionSimulation(Request $request, CustomerConnection $connection)
    {
        Gate::authorize('view', $connection);
        abort_unless(config('monitoring.simulation'), 403);
        $data = $request->validate(['state' => ['required', 'in:online,offline,unknown']]);
        $connection->update(['monitoring_state' => $data['state']]);

        return back()->with('status', 'Connection simulation state updated.');
    }

    public function history(string $type, int $id)
    {
        abort_unless(in_array($type, ['router', 'connection'], true), 404);
        $subject = $type === 'router'
            ? Router::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id)
            : CustomerConnection::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);
        $observations = HealthObservation::where('tenant_id', Auth::user()->tenant_id)->where('subject_type', $type)->where('subject_id', $id)->latest('observed_at')->limit(20)->get();

        return view('monitoring.history', compact('observations', 'type', 'id', 'subject'));
    }

    public function incident(OutageIncident $incident)
    {
        Gate::authorize('view', $incident);
        $incident->load(['router', 'affectedConnections.customer']);

        return view('monitoring.incident', compact('incident'));
    }

    public function acknowledge(OutageIncident $incident)
    {
        Gate::authorize('acknowledge', $incident);
        $incident->update(['status' => 'acknowledged', 'acknowledged_at' => now()]);

        return back()->with('status', 'Outage incident acknowledged.');
    }
}

<?php

namespace App\Services\Monitoring;

use App\Models\CustomerConnection;
use App\Models\HealthObservation;
use App\Models\OutageIncident;
use App\Models\Router;
use App\Models\User;

class OutageCorrelationService
{
    private int $threshold;

    private int $windowMinutes;

    public function __construct(?int $threshold = null, ?int $windowMinutes = null)
    {
        $this->threshold = $threshold ?? config('outages.minimum_connections', 3);
        $this->windowMinutes = $windowMinutes ?? config('outages.window_minutes', 10);
    }

    public function correlate(int $tenantId, User $user): void
    {
        abort_unless($tenantId === $user->tenant_id, 403);
        Router::where('tenant_id', $tenantId)->get()->each(fn (Router $router) => $this->correlateRouter($router));
    }

    private function correlateRouter(Router $router): void
    {
        $connections = CustomerConnection::where('tenant_id', $router->tenant_id)->where('router_id', $router->id)->where('status', 'active')->whereNotNull('provisioned_at')->with('customer')->get();
        $latest = HealthObservation::where('tenant_id', $router->tenant_id)->where('subject_type', 'connection')->whereIn('subject_id', $connections->pluck('id'))->where('observed_at', '>=', now()->subMinutes($this->windowMinutes))->latest('observed_at')->get()->unique('subject_id')->keyBy('subject_id');
        $offline = $connections->filter(fn (CustomerConnection $connection) => $latest->get($connection->id)?->health_state === HealthState::OFFLINE->value);
        $incident = OutageIncident::where('tenant_id', $router->tenant_id)->where('router_id', $router->id)->whereIn('status', ['detected', 'acknowledged'])->latest('detected_at')->first();

        if ($offline->count() >= $this->threshold) {
            $incident ??= OutageIncident::create(['tenant_id' => $router->tenant_id, 'router_id' => $router->id, 'status' => 'detected', 'detected_at' => now(), 'correlation_count' => $offline->count(), 'evidence_window_minutes' => $this->windowMinutes]);
            $incident->update(['correlation_count' => $offline->count()]);
            $incident->affectedConnections()->sync($offline->mapWithKeys(fn (CustomerConnection $connection) => [$connection->id => ['customer_id' => $connection->customer_id]])->all());

            return;
        }

        if ($incident && $incident->affectedConnections()->count() > 0) {
            $affected = $incident->affectedConnections()->get();
            $recovered = $affected->every(fn (CustomerConnection $connection) => $latest->get($connection->id)?->health_state === HealthState::ONLINE->value);
            if ($recovered) {
                $incident->update(['status' => 'resolved', 'resolved_at' => now()]);
            }
        }
    }
}

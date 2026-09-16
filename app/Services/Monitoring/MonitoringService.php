<?php

namespace App\Services\Monitoring;

use App\Models\CustomerConnection;
use App\Models\HealthObservation;
use App\Models\Router;
use App\Models\User;
use App\Services\Monitoring\Contracts\MonitoringDriver;
use Illuminate\Support\Collection;

class MonitoringService
{
    public function __construct(private readonly MonitoringDriver $driver) {}

    public function observeRouter(Router $router, User $user): HealthObservation
    {
        abort_unless($router->tenant_id === $user->tenant_id, 403);

        return $this->persist('router', $router->id, $router->tenant_id, $this->driver->observeRouter($router));
    }

    public function observeConnection(CustomerConnection $connection, User $user): HealthObservation
    {
        abort_unless($connection->tenant_id === $user->tenant_id, 403);

        return $this->persist('connection', $connection->id, $connection->tenant_id, $this->driver->observeConnection($connection));
    }

    public function observeTenant(int $tenantId, User $user): Collection
    {
        abort_unless($tenantId === $user->tenant_id, 403);
        $observations = collect();
        Router::where('tenant_id', $tenantId)->get()->each(fn (Router $router) => $observations->push($this->observeRouter($router, $user)));
        CustomerConnection::where('tenant_id', $tenantId)->whereNotNull('provisioned_at')->with(['router', 'customer'])->get()->each(fn (CustomerConnection $connection) => $observations->push($this->observeConnection($connection, $user)));

        return $observations;
    }

    private function persist(string $subjectType, int $subjectId, int $tenantId, HealthObservationResult $result): HealthObservation
    {
        return HealthObservation::create(['tenant_id' => $tenantId, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'health_state' => $result->state->value, 'reachable' => $result->reachable, 'online' => $result->online, 'latency_ms' => $result->latencyMs, 'packet_loss_percent' => $result->packetLossPercent, 'observed_at' => $result->observedAt, 'provider' => config('monitoring.driver'), 'metadata' => $result->metadata]);
    }
}

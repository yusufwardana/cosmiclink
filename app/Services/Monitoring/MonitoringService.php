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
    public function __construct(private readonly MonitoringDriver $driver, private readonly OutageCorrelationService $correlation) {}

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
        $this->correlation->correlate($tenantId, $user);

        return $observations;
    }

    public function runScheduled(): int
    {
        $count = 0;
        User::query()->whereNotNull('tenant_id')->orderBy('id')->get()->groupBy('tenant_id')->each(function ($users, $tenantId) use (&$count) {
            $count += $this->observeTenant((int) $tenantId, $users->first())->count();
        });

        return $count;
    }

    public function prune(): int
    {
        return HealthObservation::where('observed_at', '<', now()->subDays((int) config('monitoring.retention_days', 14)))->delete();
    }

    private function persist(string $subjectType, int $subjectId, int $tenantId, HealthObservationResult $result): HealthObservation
    {
        $latest = HealthObservation::where('tenant_id', $tenantId)->where('subject_type', $subjectType)->where('subject_id', $subjectId)->latest('observed_at')->first();
        $checkpointDue = ! $latest || $latest->observed_at->lt(now()->subSeconds((int) config('monitoring.checkpoint_seconds', 300)));
        if ($latest && ! $checkpointDue && $latest->health_state === $result->state->value) {
            return $latest;
        }

        return HealthObservation::create(['tenant_id' => $tenantId, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'health_state' => $result->state->value, 'reachable' => $result->reachable, 'online' => $result->online, 'latency_ms' => $result->latencyMs, 'packet_loss_percent' => $result->packetLossPercent, 'observed_at' => $result->observedAt, 'provider' => config('monitoring.driver'), 'metadata' => $result->metadata]);
    }
}

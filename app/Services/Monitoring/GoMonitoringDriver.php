<?php

namespace App\Services\Monitoring;

use App\Models\CustomerConnection;
use App\Models\Router;
use App\Services\Monitoring\Contracts\MonitoringDriver;
use App\Services\Network\GoNetworkMonitoringClient;
use Illuminate\Support\Carbon;

class GoMonitoringDriver implements MonitoringDriver
{
    public function __construct(private readonly GoNetworkMonitoringClient $client, private readonly RouterCapabilityService $capabilities) {}

    public function observeRouter(Router $router): HealthObservationResult
    {
        $payload = $this->client->collect($router);
        $observedAt = $this->observedAt($payload);
        if ($observedAt->lt(now()->subSeconds((int) config('monitoring.freshness_seconds', 180)))) {
            return new HealthObservationResult(HealthState::UNKNOWN, null, null, null, null, now(), ['failure_code' => 'OBSERVATION_STALE', 'source' => 'go-engine']);
        }
        if (($payload['reachable'] ?? false) !== true) {
            return new HealthObservationResult(HealthState::UNKNOWN, false, null, null, null, $observedAt, [
                'failure_code' => data_get($payload, 'failure.code', 'ENGINE_UNAVAILABLE'),
                'source' => 'go-engine',
            ]);
        }

        $this->capabilities->recordHealthEvidence($router, $payload, $observedAt);

        $memoryTotal = (int) ($payload['memory_total_bytes'] ?? 0);
        $memoryFree = (int) ($payload['memory_free_bytes'] ?? 0);
        $memoryUsed = $memoryTotal > 0 ? (int) round((($memoryTotal - $memoryFree) / $memoryTotal) * 100) : null;

        return new HealthObservationResult(HealthState::ONLINE, true, true, null, 0, $observedAt, [
            'source' => 'go-engine',
            'identity' => $payload['identity'] ?? null,
            'version' => $payload['version'] ?? null,
            'architecture' => $payload['architecture'] ?? null,
            'board' => $payload['board'] ?? null,
            'uptime_seconds' => $payload['uptime'] ?? null,
            'cpu_load_percent' => $payload['cpu_load_percent'] ?? null,
            'memory_used_percent' => $memoryUsed,
            'ppp_active_count' => count($payload['ppp_active'] ?? []),
            'ppp_active_names' => collect($payload['ppp_active'] ?? [])->pluck('name')->filter()->take(100)->values()->all(),
        ]);
    }

    public function observeConnection(CustomerConnection $connection): HealthObservationResult
    {
        if (! $connection->network_account_id || ! $connection->relationLoaded('router')) {
            $connection->load('router', 'networkAccount');
        }
        if (! $connection->network_account_id || ! $connection->networkAccount) {
            return new HealthObservationResult(HealthState::UNKNOWN, null, null, null, null, now(), ['failure_code' => 'UNMAPPED_CONNECTION', 'source' => 'go-engine']);
        }

        $payload = $this->client->collect($connection->router);
        if (($payload['reachable'] ?? false) !== true) {
            return new HealthObservationResult(HealthState::UNKNOWN, false, null, null, null, $this->observedAt($payload), ['failure_code' => data_get($payload, 'failure.code', 'ENGINE_UNAVAILABLE'), 'source' => 'go-engine']);
        }

        $username = (string) $connection->networkAccount->username;
        $online = collect($payload['ppp_active'] ?? [])->contains(fn (array $session) => ($session['name'] ?? null) === $username);

        return new HealthObservationResult($online ? HealthState::ONLINE : HealthState::OFFLINE, true, $online, null, 0, $this->observedAt($payload), ['source' => 'go-engine', 'account_username' => $username]);
    }

    private function observedAt(array $payload): Carbon
    {
        return isset($payload['collected_at']) ? Carbon::parse($payload['collected_at']) : now();
    }
}

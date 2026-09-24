<?php

namespace App\Services\Monitoring;

use App\Models\CustomerConnection;
use App\Models\DeviceObservation;
use App\Models\LiveConnectionState;
use App\Models\TrafficSample;
use Illuminate\Support\Facades\Cache;

final class LiveMonitoringService
{
    public function refreshConnection(CustomerConnection $connection): LiveConnectionState
    {
        $now = now();
        $freshAfter = $now->copy()->subSeconds((int) config('monitoring.live.freshness_seconds', 30));
        $observation = DeviceObservation::query()
            ->where('tenant_id', $connection->tenant_id)
            ->where('router_id', $connection->router_id)
            ->where('customer_connection_id', $connection->id)
            ->where('last_seen_at', '>=', $freshAfter)
            ->latest('last_seen_at')
            ->first();

        $traffic = TrafficSample::query()
            ->where('tenant_id', $connection->tenant_id)
            ->where('router_id', $connection->router_id)
            ->where('observed_at', '>=', $freshAfter)
            ->where(function ($query) use ($connection) {
                $identity = strtolower(trim((string) ($connection->metadata['network_identity'] ?? '')));
                $query->whereRaw('LOWER(subject_key) = ?', [$identity])
                    ->orWhereRaw('LOWER(source_key) = ?', [$identity]);
            })
            ->latest('observed_at')
            ->first();

        $existing = LiveConnectionState::query()
            ->where('tenant_id', $connection->tenant_id)
            ->where('customer_connection_id', $connection->id)
            ->first();

        $present = $observation !== null || $traffic !== null;
        $failureStreak = $present ? 0 : (($existing?->failure_streak ?? 0) + 1);
        $offlineThreshold = max(2, (int) config('monitoring.live.offline_failure_threshold', 3));
        $state = $present
            ? (($traffic?->upload_delta_bytes ?? 0) > 0 || ($traffic?->download_delta_bytes ?? 0) > 0 ? 'online_active' : 'online_idle')
            : ($failureStreak >= $offlineThreshold ? 'offline' : 'suspected_offline');

        $source = $observation?->source ?? ($traffic?->source_type ?? 'absence');
        $confidence = $present ? ($observation && $traffic ? 100 : 80) : ($state === 'offline' ? 90 : 50);

        $live = LiveConnectionState::updateOrCreate(
            ['tenant_id' => $connection->tenant_id, 'customer_connection_id' => $connection->id],
            [
                'router_id' => $connection->router_id,
                'state' => $state,
                'signal_source' => $source,
                'confidence' => $confidence,
                'upload_bps' => $this->bps($traffic?->upload_delta_bytes, $traffic),
                'download_bps' => $this->bps($traffic?->download_delta_bytes, $traffic),
                'latency_ms' => null,
                'failure_streak' => $failureStreak,
                'first_seen_at' => $existing?->first_seen_at ?? ($present ? $now : null),
                'last_seen_at' => $present ? $now : $existing?->last_seen_at,
                'observed_at' => $now,
                'metadata' => ['access_mode' => $connection->access_mode, 'network_mechanism' => $connection->network_mechanism],
            ]
        );

        Cache::put($this->cacheKey($connection), $live->toArray(), now()->addSeconds((int) config('monitoring.live.cache_ttl_seconds', 90)));

        return $live;
    }

    public function refreshRouter(int $routerId): int
    {
        $count = 0;
        CustomerConnection::query()->where('router_id', $routerId)->whereNotNull('router_id')->each(function (CustomerConnection $connection) use (&$count) {
            $this->refreshConnection($connection);
            $count++;
        });

        return $count;
    }

    private function bps(?int $delta, ?TrafficSample $sample): ?int
    {
        if ($delta === null || ! $sample) {
            return null;
        }

        $seconds = max(1, (int) config('monitoring.traffic.collection_interval_seconds', 60));

        return (int) round(($delta * 8) / $seconds);
    }

    private function cacheKey(CustomerConnection $connection): string
    {
        return 'live:connection:'.$connection->tenant_id.':'.$connection->id;
    }
}

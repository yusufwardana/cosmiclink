<?php

namespace App\Services\Monitoring;

use App\Models\CustomerConnection;
use App\Models\DeviceObservation;
use App\Models\LiveConnectionState;
use App\Models\Router;
use App\Models\TrafficCollection;
use App\Models\TrafficSample;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class LiveMonitoringService
{
    public function refreshConnection(CustomerConnection $connection, LiveMonitoringCycle $cycle): LiveConnectionState
    {
        return DB::transaction(function () use ($connection, $cycle): LiveConnectionState {
            $existing = LiveConnectionState::query()
                ->where('tenant_id', $connection->tenant_id)
                ->where('customer_connection_id', $connection->id)
                ->lockForUpdate()
                ->first();

            $live = $this->evaluate($connection, $cycle, $existing);
            DB::afterCommit(function () use ($connection, $live): void {
                Cache::put(
                    $this->cacheKey($connection),
                    $live->toArray(),
                    now()->addSeconds((int) config('monitoring.live.cache_ttl_seconds', 90)),
                );
            });

            return $live;
        });
    }

    private function evaluate(CustomerConnection $connection, LiveMonitoringCycle $cycle, ?LiveConnectionState $existing): LiveConnectionState
    {
        $freshness = max(1, (int) config('monitoring.live.freshness_seconds', 30));
        $freshAfter = $cycle->observedAt->subSeconds($freshness);
        $cycleIsStale = $cycle->observedAt->lt(now()->subSeconds($freshness));
        $observation = DeviceObservation::query()
            ->where('tenant_id', $connection->tenant_id)
            ->where('router_id', $connection->router_id)
            ->where('customer_connection_id', $connection->id)
            ->where('last_seen_at', '>=', $freshAfter)
            ->latest('last_seen_at')
            ->first();

        $trafficSamples = TrafficSample::query()
            ->where('tenant_id', $connection->tenant_id)
            ->where('router_id', $connection->router_id)
            ->where('observed_at', '>=', $freshAfter)
            ->where(function ($query) use ($connection) {
                $identity = strtolower(trim((string) ($connection->metadata['network_identity'] ?? '')));
                $query->whereRaw('LOWER(subject_key) = ?', [$identity])
                    ->orWhereRaw('LOWER(source_key) = ?', [$identity]);
            })
            ->orderByDesc('observed_at')
            ->get();
        $latestTrafficAt = $trafficSamples->first()?->observed_at;
        $latestTraffic = $latestTrafficAt
            ? $trafficSamples->filter(fn (TrafficSample $sample): bool => $sample->observed_at->equalTo($latestTrafficAt))->values()
            : collect();
        $trafficPresent = $latestTraffic->contains(function (TrafficSample $sample): bool {
            if ($sample->source_type === 'hotspot_session') {
                return true;
            }

            return $sample->delta_status === 'valid'
                && (($sample->upload_delta_bytes ?? 0) > 0 || ($sample->download_delta_bytes ?? 0) > 0);
        });
        $present = $observation !== null || $trafficPresent;
        $offlineThreshold = max(2, (int) config('monitoring.live.offline_failure_threshold', 3));
        $previousStreak = $existing?->failure_streak ?? 0;
        if (! $cycle->isSuccessful()) {
            $state = LiveConnectionStatus::UNKNOWN;
            $failureStreak = $previousStreak;
        } elseif ($cycleIsStale) {
            $state = LiveConnectionStatus::STALE;
            $failureStreak = $previousStreak;
        } elseif ($present) {
            $state = LiveConnectionStatus::ONLINE;
            $failureStreak = 0;
        } else {
            $failureStreak = min($offlineThreshold, $previousStreak + 1);
            $state = $failureStreak >= $offlineThreshold
                ? LiveConnectionStatus::OFFLINE
                : LiveConnectionStatus::SUSPECTED_OFFLINE;
        }

        [$uploadBps, $downloadBps] = $this->rates($latestTraffic);
        $lastSeenAt = collect([$observation?->last_seen_at, $latestTrafficAt])->filter()->sortDesc()->first();
        $source = $observation?->source ?? ($latestTraffic->first()?->source_type ?? $cycle->source);
        $confidence = match ($state) {
            LiveConnectionStatus::ONLINE => $observation && $trafficPresent ? 100 : 80,
            LiveConnectionStatus::OFFLINE => 90,
            LiveConnectionStatus::SUSPECTED_OFFLINE => 50,
            LiveConnectionStatus::UNKNOWN, LiveConnectionStatus::STALE => 0,
        };

        $live = LiveConnectionState::updateOrCreate(
            ['tenant_id' => $connection->tenant_id, 'customer_connection_id' => $connection->id],
            [
                'router_id' => $connection->router_id,
                'state' => $state->value,
                'signal_source' => $source,
                'confidence' => $confidence,
                'upload_bps' => $uploadBps,
                'download_bps' => $downloadBps,
                'latency_ms' => null,
                'failure_streak' => $failureStreak,
                'first_seen_at' => $existing?->first_seen_at ?? ($present ? $lastSeenAt : null),
                'last_seen_at' => $present ? $lastSeenAt : $existing?->last_seen_at,
                'observed_at' => $cycle->observedAt,
                'metadata' => array_filter([
                    'access_mode' => $connection->access_mode,
                    'network_mechanism' => $connection->network_mechanism,
                    'activity_state' => $state === LiveConnectionStatus::ONLINE
                        ? ((($uploadBps ?? 0) > 0 || ($downloadBps ?? 0) > 0) ? 'active' : 'idle')
                        : null,
                    'failure_code' => $cycle->failureCode,
                ], fn (mixed $value): bool => $value !== null),
            ]
        );

        return $live;
    }

    public function refreshRouter(Router $router, LiveMonitoringCycle $cycle): int
    {
        $count = 0;
        CustomerConnection::query()
            ->where('tenant_id', $router->tenant_id)
            ->where('router_id', $router->id)
            ->each(function (CustomerConnection $connection) use (&$count, $cycle) {
                $this->refreshConnection($connection, $cycle);
                $count++;
            });

        return $count;
    }

    private function rates(Collection $samples): array
    {
        $valid = $samples->where('delta_status', 'valid');
        if ($valid->isEmpty()) {
            return [null, null];
        }

        /** @var TrafficSample $sample */
        $sample = $valid->first();
        $currentCollection = TrafficCollection::query()->find($sample->traffic_collection_id);
        if (! $currentCollection) {
            return [null, null];
        }
        $previousCollection = TrafficCollection::query()
            ->where('tenant_id', $sample->tenant_id)
            ->where('router_id', $sample->router_id)
            ->where('collected_at', '<', $currentCollection->collected_at)
            ->latest('collected_at')
            ->first();
        if (! $previousCollection) {
            return [null, null];
        }

        $seconds = max(1, $previousCollection->collected_at->diffInSeconds($currentCollection->collected_at));
        $upload = $valid->sum(fn (TrafficSample $traffic): int => $traffic->upload_delta_bytes ?? 0);
        $download = $valid->sum(fn (TrafficSample $traffic): int => $traffic->download_delta_bytes ?? 0);

        return [
            (int) round(($upload * 8) / $seconds),
            (int) round(($download * 8) / $seconds),
        ];
    }

    private function cacheKey(CustomerConnection $connection): string
    {
        return 'live:connection:'.$connection->tenant_id.':'.$connection->id;
    }
}

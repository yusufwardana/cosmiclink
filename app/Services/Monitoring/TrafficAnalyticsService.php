<?php

namespace App\Services\Monitoring;

use App\Models\DiscoveredNetworkResource;
use App\Models\NetworkAccount;
use App\Models\TrafficBucket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TrafficAnalyticsService
{
    public function overview(int $tenantId, TrafficAnalyticsPeriod $period, array $filters = []): array
    {
        $buckets = $this->bucketQuery($tenantId, $period, $filters)->get();
        $mapping = $this->mappingContext($tenantId, $filters);

        $staticBuckets = $this->mappedBuckets($buckets->where('source_type', 'simple_queue')->where('subscriber_authoritative', true), $mapping);
        $hotspotBuckets = $this->mappedBuckets($buckets->where('source_type', 'hotspot_username')->where('subscriber_authoritative', true), $mapping);
        $static = $this->metrics($staticBuckets);
        $hotspot = $this->metrics($hotspotBuckets);
        $interfaces = $this->metrics($buckets->where('source_type', 'interface'));

        return [
            'period' => $this->periodData($period),
            'subscriber_modes' => [
                TrafficConnectionMode::STATIC_SIMPLE_QUEUE => $static,
                TrafficConnectionMode::HOTSPOT => $hotspot,
                TrafficConnectionMode::PPPOE => ['supported' => false, 'reason' => 'AUTHORITATIVE_TRAFFIC_UNAVAILABLE'],
            ],
            'interfaces' => $interfaces,
            'peak_hour' => [
                'subscriber_modes' => [
                    TrafficConnectionMode::STATIC_SIMPLE_QUEUE => $this->peakHour($staticBuckets),
                    TrafficConnectionMode::HOTSPOT => $this->peakHour($hotspotBuckets),
                    TrafficConnectionMode::PPPOE => ['supported' => false, 'reason' => 'AUTHORITATIVE_TRAFFIC_UNAVAILABLE'],
                ],
                'interfaces' => $this->peakHour($buckets->where('source_type', 'interface')),
            ],
        ];
    }

    public function rankSubscribers(int $tenantId, TrafficAnalyticsPeriod $period, string $mode, string $metric, int $limit, array $filters = []): array
    {
        $source = TrafficConnectionMode::bucketSource($mode);
        if ($source === null) {
            return ['supported' => false, 'mode' => $mode, 'reason' => 'AUTHORITATIVE_TRAFFIC_UNAVAILABLE'];
        }

        $mapping = $this->mappingContext($tenantId, $filters);
        if ($mapping['filtered'] && $mapping['keys'] === []) {
            return [];
        }

        $query = $this->bucketQuery($tenantId, $period, $filters)
            ->where('source_type', $source)
            ->where('subscriber_authoritative', true);
        if ($mapping['filtered']) {
            $this->restrictToMappingKeys($query, $mapping['keys']);
        }

        $rows = $query
            ->selectRaw('router_id, subject_key, SUM(upload_bytes) AS upload_bytes, SUM(download_bytes) AS download_bytes, SUM(upload_bytes + download_bytes) AS total_bytes')
            ->groupBy('router_id', 'subject_key')
            ->get()
            ->map(fn ($row) => [
                'router_id' => (int) $row->router_id,
                'identity' => (string) $row->subject_key,
                'upload_bytes' => (int) $row->upload_bytes,
                'download_bytes' => (int) $row->download_bytes,
                'total_bytes' => (int) $row->total_bytes,
            ]);

        $column = match ($metric) {
            'upload' => 'upload_bytes',
            'download' => 'download_bytes',
            default => 'total_bytes',
        };
        $rows = $rows->sort(function (array $left, array $right) use ($metric, $column) {
            $comparison = $metric === 'lowest'
                ? $left[$column] <=> $right[$column]
                : $right[$column] <=> $left[$column];

            return $comparison !== 0 ? $comparison : [$left['identity'], $left['router_id']] <=> [$right['identity'], $right['router_id']];
        })->take($limit)->values();

        $accounts = $mapping['accounts'] ?? $this->loadMappings($tenantId, $rows->all());
        $enriched = $rows->map(fn (array $row) => array_merge($row, $this->enrichment($accounts, $row['router_id'], $row['identity'])));

        // Customer/connection identity outranks a Discovery-only queue label, so
        // the persisted Discovery inventory is only read when at least one ranked
        // row still lacks a customer mapping. Customer/package filters and
        // non-STATIC_SIMPLE_QUEUE modes never consult Discovery.
        $needsDiscovery = $enriched->contains(fn (array $row) => $row['mapped'] === false);
        $queues = ($mapping['filtered'] || $source !== 'simple_queue' || ! $needsDiscovery)
            ? collect()
            : $this->discoveryQueues($enriched, $tenantId);

        return $enriched->map(fn (array $row) => array_merge($row, [
            'discovery' => $row['mapped'] ? null : $this->discoveryTargetFallback($row, $queues, $tenantId),
        ]))->all();
    }

    public function subscriberHistory(int $tenantId, TrafficAnalyticsPeriod $period, string $mode, string $identity, array $filters = []): array
    {
        $source = TrafficConnectionMode::bucketSource($mode);
        if ($source === null) {
            return ['supported' => false, 'mode' => $mode, 'reason' => 'AUTHORITATIVE_TRAFFIC_UNAVAILABLE'];
        }

        $mapping = $this->mappingContext($tenantId, $filters);
        $query = $this->bucketQuery($tenantId, $period, $filters)
            ->where('source_type', $source)
            ->where('subscriber_authoritative', true)
            ->where('subject_key', strtolower(trim($identity)));
        if ($mapping['filtered']) {
            if ($mapping['keys'] === []) {
                return [];
            }
            $this->restrictToMappingKeys($query, $mapping['keys']);
        }

        return $query
            ->orderBy('bucket_started_at')
            ->get()
            ->map(fn (TrafficBucket $bucket) => $this->bucketData($bucket))
            ->all();
    }

    public function interfaceHistory(int $tenantId, TrafficAnalyticsPeriod $period, array $filters = []): array
    {
        return $this->bucketQuery($tenantId, $period, $filters)
            ->where('source_type', 'interface')
            ->orderBy('bucket_started_at')
            ->get()
            ->groupBy(fn (TrafficBucket $bucket) => $bucket->router_id.'\0'.$bucket->subject_key)
            ->map(function (Collection $buckets) {
                $first = $buckets->first();
                $metrics = $this->metrics($buckets);

                return array_merge([
                    'router_id' => $first->router_id,
                    'identity' => $first->subject_key,
                    'name' => $first->metadata['name'] ?? $first->subject_key,
                    'type' => $first->metadata['type'] ?? null,
                    'segment' => ($first->metadata['type'] ?? null) === 'bridge' ? 'client_segment' : 'interface',
                    'history' => $buckets->map(fn (TrafficBucket $bucket) => $this->bucketData($bucket))->values()->all(),
                ], $metrics);
            })
            ->values()
            ->all();
    }

    public function peakHours(int $tenantId, TrafficAnalyticsPeriod $period, array $filters = []): array
    {
        $buckets = $this->bucketQuery($tenantId, $period, $filters)->get();
        $mapping = $this->mappingContext($tenantId, $filters);
        $static = $this->mappedBuckets($buckets->where('source_type', 'simple_queue')->where('subscriber_authoritative', true), $mapping);
        $hotspot = $this->mappedBuckets($buckets->where('source_type', 'hotspot_username')->where('subscriber_authoritative', true), $mapping);

        return [
            'subscriber_modes' => [
                TrafficConnectionMode::STATIC_SIMPLE_QUEUE => $this->hourly($static)->values()->all(),
                TrafficConnectionMode::HOTSPOT => $this->hourly($hotspot)->values()->all(),
                TrafficConnectionMode::PPPOE => ['supported' => false, 'reason' => 'AUTHORITATIVE_TRAFFIC_UNAVAILABLE'],
            ],
            'interfaces' => $this->hourly($buckets->where('source_type', 'interface'))->values()->all(),
        ];
    }

    private function bucketQuery(int $tenantId, TrafficAnalyticsPeriod $period, array $filters): Builder
    {
        return TrafficBucket::query()
            ->where('tenant_id', $tenantId)
            ->where('bucket_started_at', '>=', $period->start)
            ->where('bucket_started_at', '<', $period->end)
            ->when(isset($filters['router_id']), fn (Builder $query) => $query->where('router_id', (int) $filters['router_id']));
    }

    private function metrics(Collection $buckets): array
    {
        $upload = (int) $buckets->sum('upload_bytes');
        $download = (int) $buckets->sum('download_bytes');
        $total = $upload + $download;
        $byBucket = $buckets->groupBy(fn (TrafficBucket $bucket) => $bucket->bucket_started_at->toIso8601String())
            ->map(fn (Collection $rows) => (int) $rows->sum(fn (TrafficBucket $bucket) => $bucket->upload_bytes + $bucket->download_bytes));
        $active = $byBucket->count();

        return [
            'upload_bytes' => $upload,
            'download_bytes' => $download,
            'total_bytes' => $total,
            'average_throughput_bps' => $active === 0 ? 0 : (int) round(($total * 8) / ($active * 300)),
            'peak_throughput_bps' => $active === 0 ? 0 : (int) round(((int) $byBucket->max() * 8) / 300),
            'active_bucket_count' => $active,
        ];
    }

    private function peakHour(Collection $buckets): ?array
    {
        return $this->hourly($buckets)->first();
    }

    private function hourly(Collection $buckets): Collection
    {
        return $buckets
            ->groupBy(fn (TrafficBucket $bucket) => $bucket->bucket_started_at->copy()->startOfHour()->toIso8601String())
            ->map(function (Collection $rows, string $hour) {
                $upload = (int) $rows->sum('upload_bytes');
                $download = (int) $rows->sum('download_bytes');

                return ['hour' => $hour, 'upload_bytes' => $upload, 'download_bytes' => $download, 'total_bytes' => $upload + $download];
            })
            ->sort(fn (array $left, array $right) => ($right['total_bytes'] <=> $left['total_bytes']) ?: ($left['hour'] <=> $right['hour']));
    }

    private function bucketData(TrafficBucket $bucket): array
    {
        return [
            'bucket_started_at' => $bucket->bucket_started_at->toIso8601String(),
            'upload_bytes' => $bucket->upload_bytes,
            'download_bytes' => $bucket->download_bytes,
            'total_bytes' => $bucket->upload_bytes + $bucket->download_bytes,
        ];
    }

    private function mappingContext(int $tenantId, array $filters): array
    {
        $filtered = collect(['customer_id', 'connection_id', 'package_id'])->contains(fn (string $key) => isset($filters[$key]));
        if (! $filtered) {
            return ['filtered' => false, 'keys' => []];
        }

        $accounts = NetworkAccount::query()
            ->where('tenant_id', $tenantId)
            ->with(['connections' => fn ($query) => $query
                ->when(isset($filters['customer_id']), fn ($query) => $query->where('customer_id', (int) $filters['customer_id']))
                ->when(isset($filters['connection_id']), fn ($query) => $query->whereKey((int) $filters['connection_id']))
                ->when(isset($filters['package_id']), fn ($query) => $query->where('internet_package_id', (int) $filters['package_id']))
                ->with(['customer:id,name', 'internetPackage:id,name'])])
            ->get()
            ->filter(fn (NetworkAccount $account) => $account->connections->isNotEmpty());

        return [
            'filtered' => true,
            'keys' => $accounts->map(fn (NetworkAccount $account) => [$account->router_id, strtolower(trim($account->username))])->all(),
            'accounts' => $accounts->keyBy(fn (NetworkAccount $account) => $account->router_id.'\0'.strtolower(trim($account->username))),
        ];
    }

    private function restrictToMappingKeys(Builder $query, array $keys): void
    {
        $query->where(function (Builder $outer) use ($keys) {
            foreach ($keys as [$routerId, $identity]) {
                $outer->orWhere(fn (Builder $pair) => $pair->where('router_id', $routerId)->where('subject_key', $identity));
            }
        });
    }

    private function mappedBuckets(Collection $buckets, array $mapping): Collection
    {
        if (! $mapping['filtered']) {
            return $buckets;
        }

        $allowed = collect($mapping['keys'])
            ->mapWithKeys(fn (array $key) => [$key[0].'\0'.$key[1] => true]);

        return $buckets->filter(fn (TrafficBucket $bucket) => $allowed->has($bucket->router_id.'\0'.strtolower(trim($bucket->subject_key))));
    }

    private function loadMappings(int $tenantId, array $rows): Collection
    {
        if ($rows === []) {
            return collect();
        }

        $routerIds = array_values(array_unique(array_column($rows, 'router_id')));
        $identities = array_values(array_unique(array_column($rows, 'identity')));

        return NetworkAccount::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('router_id', $routerIds)
            ->whereIn(DB::raw('LOWER(BTRIM(username))'), $identities)
            ->with(['connections.customer:id,name', 'connections.internetPackage:id,name'])
            ->get()
            ->keyBy(fn (NetworkAccount $account) => $account->router_id.'\0'.strtolower(trim($account->username)));
    }

    private function enrichment(Collection $accounts, int $routerId, string $identity): array
    {
        $account = $accounts->get($routerId.'\0'.strtolower(trim($identity)));
        $connection = $account?->connections->sortBy('id')->first();

        return [
            'mapped' => $connection !== null,
            'customer' => $connection ? ['id' => $connection->customer_id, 'name' => $connection->customer?->name] : null,
            'connection' => $connection ? ['id' => $connection->id, 'code' => $connection->connection_code] : null,
            'package' => $connection?->internetPackage ? ['id' => $connection->internet_package_id, 'name' => $connection->internetPackage->name] : null,
        ];
    }

    /**
     * Discovery fallback for one in-memory traffic row. Returns the queue
     * payload for the row (`display_name`, `management_state`) only when the
     * traffic identity itself is a subscriber-shaped target (/32 IPv4) that
     * exactly matches a persisted Discovery Simple Queue target on the SAME
     * router in the SAME tenant. Anything else stays unmapped.
     */
    private function discoveryTargetFallback(array $row, Collection $queues, int $tenantId): ?array
    {
        $target = strtolower(trim((string) ($row['identity'] ?? '')));
        if (! $this->isSubscriberQueueTarget($target)) {
            return null;
        }

        foreach ($queues as $queue) {
            if ((int) $queue[1] !== (int) ($row['router_id'] ?? 0)) {
                continue;
            }
            if ($queue[0] !== $target || (int) $queue[4] !== $tenantId) {
                continue;
            }

            return ['display_name' => (string) $queue[2], 'management_state' => (string) $queue[3]];
        }

        return null;
    }

    /**
     * Persisted Discovery Simple Queue rows for the routers on one ranking
     * page, as [target, router, name, state, tenant] tuples. One batched read
     * scoped to the tenant and routers actually ranked — never a RouterOS
     * call, never a per-row lookup.
     */
    private function discoveryQueues(Collection $rows, int $tenantId): Collection
    {
        $routerIds = array_values(array_unique(array_filter(array_map('intval', $rows->pluck('router_id')->all()), fn (int $id) => $id > 0)));
        if ($routerIds === []) {
            return collect();
        }

        return DiscoveredNetworkResource::query()
            ->where('tenant_id', $tenantId)
            ->where('resource_type', 'queue')
            ->whereIn('router_id', $routerIds)
            ->whereNotNull('normalized_data->target')
            ->orderBy('id')
            ->get(['tenant_id', 'router_id', 'name', 'management_state', 'normalized_data'])
            ->map(fn (DiscoveredNetworkResource $resource) => [
                strtolower(trim((string) ($resource->normalized_data['target'] ?? ''))),
                (int) $resource->router_id,
                (string) $resource->name,
                (string) $resource->management_state,
                (int) $resource->tenant_id,
            ]);
    }

    /**
     * True only for a target that designates ONE host address: a single token
     * whose position is exactly "x.y.z.w/32". A RouterOS target is either
     * multi-token ("addr/24,addr/24,..."), a bare short prefix (…/24), or an
     * unparsable '/' selector — each reads as aggregate or unknown and never
     * as a subscriber.
     */
    private function isSubscriberQueueTarget(?string $target): bool
    {
        if (! is_string($target) || $target === '' || str_contains($target, ',') || ! str_ends_with($target, '/32')) {
            return false;
        }
        $position = strrpos($target, '/');

        return is_int($position) && filter_var(substr($target, 0, $position), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    private function periodData(TrafficAnalyticsPeriod $period): array
    {
        return [
            'key' => $period->key,
            'start' => $period->start->toIso8601String(),
            'end' => $period->end->toIso8601String(),
        ];
    }
}

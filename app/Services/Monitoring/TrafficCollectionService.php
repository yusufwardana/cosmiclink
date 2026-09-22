<?php

namespace App\Services\Monitoring;

use App\Models\Router;
use App\Models\TrafficBucket;
use App\Models\TrafficCollection;
use App\Models\TrafficSample;
use App\Services\Network\GoNetworkMonitoringClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class TrafficCollectionService
{
    private const REQUIRED_DATASETS = ['system_resource', 'interfaces', 'simple_queues', 'hotspot_sessions'];

    private const SOURCE_MAP = [
        'interfaces' => 'interface',
        'simple_queues' => 'simple_queue',
        'hotspot_sessions' => 'hotspot_session',
    ];

    public function __construct(private readonly GoNetworkMonitoringClient $client) {}

    public function collectRouter(Router $router): ?TrafficCollection
    {
        if (! $this->enabled()) {
            return null;
        }

        $includeEnrichment = $this->enrichmentDue($router);
        $response = $this->client->collectTraffic($router, $includeEnrichment);
        $snapshot = $this->validatedSnapshot($response);
        if ($snapshot === null) {
            return null;
        }

        return DB::transaction(function () use ($router, $snapshot) {
            $lockedRouter = Router::query()
                ->whereKey($router->id)
                ->where('tenant_id', $router->tenant_id)
                ->lockForUpdate()
                ->first();
            if (! $lockedRouter) {
                return null;
            }

            $collectedAt = $snapshot['collected_at'];
            if (TrafficCollection::query()->where('tenant_id', $lockedRouter->tenant_id)->where('router_id', $lockedRouter->id)->where('collected_at', $collectedAt)->exists()) {
                return null;
            }

            $latestCollection = TrafficCollection::query()
                ->where('tenant_id', $lockedRouter->tenant_id)
                ->where('router_id', $lockedRouter->id)
                ->latest('collected_at')
                ->first();
            $delayed = $latestCollection && $collectedAt->lt($latestCollection->collected_at);
            $previousCollection = TrafficCollection::query()
                ->where('tenant_id', $lockedRouter->tenant_id)
                ->where('router_id', $lockedRouter->id)
                ->where('collected_at', '<', $collectedAt)
                ->latest('collected_at')
                ->first();

            $collection = TrafficCollection::create([
                'tenant_id' => $lockedRouter->tenant_id,
                'router_id' => $lockedRouter->id,
                'collected_at' => $collectedAt,
                'provider' => 'routeros',
                'datasets' => $snapshot['evidence']['datasets'],
                'enrichment_collected' => $snapshot['evidence']['enrichment_collected'],
                'metadata' => $this->sanitize([
                    'router' => $snapshot['router'],
                    'dhcp_leases' => $snapshot['dhcp_leases'],
                    'arp_entries' => $snapshot['arp_entries'],
                ]),
            ]);

            foreach (self::SOURCE_MAP as $dataset => $sourceType) {
                foreach ($snapshot[$dataset] as $source) {
                    $this->persistSample($collection, $previousCollection, $sourceType, $source, $delayed);
                }
            }

            return $collection;
        });
    }

    public function runScheduled(): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $count = 0;
        Router::query()->orderBy('id')->each(function (Router $router) use (&$count) {
            if ($this->collectRouter($router)) {
                $count++;
            }
        });

        return $count;
    }

    public function prune(): array
    {
        $collections = TrafficCollection::query()
            ->where('collected_at', '<', now()->subDays((int) config('monitoring.traffic.raw_retention_days', 14)))
            ->delete();
        $buckets = TrafficBucket::query()
            ->where('bucket_started_at', '<', now()->subDays((int) config('monitoring.traffic.bucket_retention_days', 90)))
            ->delete();

        return ['collections' => $collections, 'buckets' => $buckets];
    }

    private function enabled(): bool
    {
        return config('monitoring.driver') === 'engine' && (bool) config('monitoring.traffic.enabled', false);
    }

    private function enrichmentDue(Router $router): bool
    {
        $latest = TrafficCollection::query()
            ->where('tenant_id', $router->tenant_id)
            ->where('router_id', $router->id)
            ->where('enrichment_collected', true)
            ->latest('collected_at')
            ->first();

        return ! $latest || $latest->collected_at->lte(now()->subSeconds((int) config('monitoring.traffic.enrichment_interval_seconds', 300)));
    }

    private function validatedSnapshot(array $response): ?array
    {
        if (($response['reachable'] ?? null) !== true || ! is_array($response['snapshot'] ?? null)) {
            return null;
        }
        $snapshot = $this->sanitize($response['snapshot']);
        try {
            $collectedAt = Carbon::parse($snapshot['collected_at'] ?? null)->utc();
        } catch (Throwable) {
            return null;
        }
        if ($collectedAt->gt(now()->addSeconds((int) config('monitoring.traffic.future_tolerance_seconds', 30)))) {
            return null;
        }
        $evidence = $snapshot['evidence'] ?? null;
        $datasets = is_array($evidence['datasets'] ?? null) ? array_values(array_unique($evidence['datasets'])) : [];
        if (array_diff(self::REQUIRED_DATASETS, $datasets) !== []) {
            return null;
        }
        foreach (array_keys(self::SOURCE_MAP) as $dataset) {
            if (! is_array($snapshot[$dataset] ?? null)) {
                return null;
            }
        }

        return [
            'collected_at' => $collectedAt,
            'router' => is_array($snapshot['router'] ?? null) ? $snapshot['router'] : [],
            'evidence' => ['datasets' => $datasets, 'enrichment_collected' => ($evidence['enrichment_collected'] ?? null) === true],
            'interfaces' => $snapshot['interfaces'],
            'simple_queues' => $snapshot['simple_queues'],
            'hotspot_sessions' => $snapshot['hotspot_sessions'],
            'dhcp_leases' => is_array($snapshot['dhcp_leases'] ?? null) ? $snapshot['dhcp_leases'] : [],
            'arp_entries' => is_array($snapshot['arp_entries'] ?? null) ? $snapshot['arp_entries'] : [],
        ];
    }

    private function persistSample(TrafficCollection $collection, ?TrafficCollection $previousCollection, string $sourceType, array $source, bool $delayed): void
    {
        $sourceKey = trim((string) ($source['source_key'] ?? ''));
        if ($sourceKey === '') {
            return;
        }
        $upload = $this->counter($source['upload_bytes'] ?? null);
        $download = $this->counter($source['download_bytes'] ?? null);
        $prior = TrafficSample::query()
            ->where('tenant_id', $collection->tenant_id)
            ->where('router_id', $collection->router_id)
            ->where('source_type', $sourceType)
            ->where('source_key', $sourceKey)
            ->where('observed_at', '<', $collection->collected_at)
            ->latest('observed_at')
            ->first();

        [$status, $uploadDelta, $downloadDelta] = $this->delta($collection, $previousCollection, $prior, $upload, $download, $delayed);
        $subjectKey = trim((string) ($source['subject_key'] ?? '')) ?: null;
        $metadata = $source;
        unset($metadata['source_key'], $metadata['subject_key'], $metadata['upload_bytes'], $metadata['download_bytes']);
        $sample = TrafficSample::create([
            'traffic_collection_id' => $collection->id,
            'tenant_id' => $collection->tenant_id,
            'router_id' => $collection->router_id,
            'source_type' => $sourceType,
            'source_key' => $sourceKey,
            'subject_key' => $subjectKey,
            'upload_bytes' => $upload,
            'download_bytes' => $download,
            'upload_delta_bytes' => $uploadDelta,
            'download_delta_bytes' => $downloadDelta,
            'delta_status' => $status,
            'observed_at' => $collection->collected_at,
            'metadata' => $this->sanitize($metadata),
        ]);

        if ($sample->delta_status !== 'valid') {
            return;
        }
        $bucketIdentity = $sample->source_type === 'simple_queue' && $sample->subject_key
            ? strtolower(trim($sample->subject_key))
            : $sample->source_key;
        $this->incrementBucket(
            $sample->source_type,
            $bucketIdentity,
            $sample,
            $uploadDelta,
            $downloadDelta,
            $sample->metadata ?? [],
            $sample->source_type === 'simple_queue' && ($sample->metadata['dynamic'] ?? null) === false,
        );
        if ($sample->source_type === 'hotspot_session' && $sample->subject_key) {
            $username = strtolower(trim($sample->subject_key));
            $this->incrementBucket('hotspot_username', $username, $sample, $uploadDelta, $downloadDelta, ['username' => $username], true);
        }
    }

    private function delta(TrafficCollection $collection, ?TrafficCollection $previousCollection, ?TrafficSample $prior, ?int $upload, ?int $download, bool $delayed): array
    {
        if ($delayed) {
            return ['delayed_observation', null, null];
        }
        if ($upload === null || $download === null || ($prior && ($prior->upload_bytes === null || $prior->download_bytes === null))) {
            return ['counter_unavailable', null, null];
        }
        if (! $prior) {
            return ['first_observation', null, null];
        }
        if (! $previousCollection || $prior->traffic_collection_id !== $previousCollection->id) {
            return ['source_reappeared', null, null];
        }
        if ($upload < $prior->upload_bytes || $download < $prior->download_bytes) {
            return ['counter_reset', null, null];
        }

        return ['valid', $upload - $prior->upload_bytes, $download - $prior->download_bytes];
    }

    private function incrementBucket(string $sourceType, string $subjectKey, TrafficSample $sample, int $upload, int $download, array $metadata = [], bool $subscriberAuthoritative = false): void
    {
        $bucketAt = $sample->observed_at->copy()->second(0)->microsecond(0)->minute(intdiv($sample->observed_at->minute, 5) * 5);
        $bucket = TrafficBucket::query()->firstOrCreate([
            'tenant_id' => $sample->tenant_id,
            'router_id' => $sample->router_id,
            'source_type' => $sourceType,
            'subject_key' => $subjectKey,
            'bucket_started_at' => $bucketAt,
        ], [
            'upload_bytes' => 0,
            'download_bytes' => 0,
            'sample_count' => 0,
            'metadata' => $this->bucketMetadata($sourceType, $metadata),
            'subscriber_authoritative' => $subscriberAuthoritative,
        ]);
        $bucket->metadata = $this->bucketMetadata($sourceType, $metadata);
        $bucket->subscriber_authoritative = $subscriberAuthoritative;
        $bucket->upload_bytes += $upload;
        $bucket->download_bytes += $download;
        $bucket->sample_count++;
        $bucket->save();
    }

    private function bucketMetadata(string $sourceType, array $metadata): array
    {
        $allowed = match ($sourceType) {
            'interface' => ['name', 'type', 'running', 'disabled'],
            'simple_queue' => ['name', 'target', 'dynamic', 'disabled'],
            'hotspot_username' => ['username'],
            default => [],
        };

        return array_intersect_key($this->sanitize($metadata), array_flip($allowed));
    }

    private function counter(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value)) {
            $value = ltrim($value, '0');
            $value = $value === '' ? '0' : $value;
            $maximum = (string) PHP_INT_MAX;
            if (ctype_digit($value) && (strlen($value) < strlen($maximum) || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) <= 0))) {
                return (int) $value;
            }
        }

        return null;
    }

    private function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), ['password', 'pass', 'secret', 'token', 'authorization', 'credential', 'credentials'], true)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            }
        }

        return $data;
    }
}

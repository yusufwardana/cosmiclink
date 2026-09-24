<?php

return [
    'driver' => env('MONITORING_DRIVER', 'fake'),
    'simulation' => env('MONITORING_DRIVER', 'fake') === 'fake' && in_array(env('APP_ENV', 'local'), ['local', 'demo'], true),
    'interval_seconds' => (int) env('MONITORING_INTERVAL', 60),
    'freshness_seconds' => (int) env('MONITORING_FRESHNESS_SECONDS', 180),
    'retention_days' => (int) env('MONITORING_RETENTION_DAYS', 14),
    // Health evidence must be checkpointed before the safety freshness window
    // expires. The scheduler runs every minute, so 120 seconds preserves
    // checkpoint suppression while keeping unchanged evidence inside the
    // existing 180-second freshness requirement.
    'checkpoint_seconds' => (int) env('MONITORING_CHECKPOINT_SECONDS', 120),
    'live' => [
        'freshness_seconds' => (int) env('MONITORING_LIVE_FRESHNESS_SECONDS', 30),
        'offline_failure_threshold' => (int) env('MONITORING_LIVE_OFFLINE_FAILURE_THRESHOLD', 3),
        'cache_ttl_seconds' => (int) env('MONITORING_LIVE_CACHE_TTL_SECONDS', 90),
    ],
    'traffic' => [
        'collection_interval_seconds' => (int) env('MONITORING_TRAFFIC_COLLECTION_INTERVAL_SECONDS', 60),
        'enabled' => filter_var(env('MONITORING_TRAFFIC_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'enrichment_interval_seconds' => (int) env('MONITORING_TRAFFIC_ENRICHMENT_INTERVAL_SECONDS', 300),
        'raw_retention_days' => (int) env('MONITORING_TRAFFIC_RAW_RETENTION_DAYS', 14),
        'bucket_retention_days' => (int) env('MONITORING_TRAFFIC_BUCKET_RETENTION_DAYS', 90),
        'future_tolerance_seconds' => (int) env('MONITORING_TRAFFIC_FUTURE_TOLERANCE_SECONDS', 30),
    ],
];

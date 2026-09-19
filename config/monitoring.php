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
];

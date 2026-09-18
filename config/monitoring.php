<?php

return [
    'driver' => env('MONITORING_DRIVER', 'fake'),
    'simulation' => env('MONITORING_DRIVER', 'fake') === 'fake' && in_array(env('APP_ENV', 'local'), ['local', 'demo'], true),
    'interval_seconds' => (int) env('MONITORING_INTERVAL', 60),
    'freshness_seconds' => (int) env('MONITORING_FRESHNESS_SECONDS', 180),
    'retention_days' => (int) env('MONITORING_RETENTION_DAYS', 14),
    'checkpoint_seconds' => (int) env('MONITORING_CHECKPOINT_SECONDS', 300),
];

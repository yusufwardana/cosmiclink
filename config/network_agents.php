<?php

return [
    'minimum_version' => env('NETWORK_AGENT_MINIMUM_VERSION', '0.5.0'),
    'recommended_version' => env('NETWORK_AGENT_RECOMMENDED_VERSION', '0.6.0'),
    'lease_minimum_version' => '0.6.0',
    'legacy_versions' => ['phase-6e', '6e-test'],
    'capabilities' => ['discovery.routeros.readonly', 'jobs.lease.v1'],
    'heartbeat_seconds' => (int) env('NETWORK_AGENT_HEARTBEAT_SECONDS', 30),
    'stale_seconds' => (int) env('NETWORK_AGENT_STALE_SECONDS', 90),
    'offline_seconds' => (int) env('NETWORK_AGENT_OFFLINE_SECONDS', 300),
    'lease_seconds' => (int) env('NETWORK_AGENT_LEASE_SECONDS', 120),
    'renewal_seconds' => (int) env('NETWORK_AGENT_RENEWAL_SECONDS', 30),
    'max_attempts' => (int) env('NETWORK_AGENT_MAX_ATTEMPTS', 3),
];

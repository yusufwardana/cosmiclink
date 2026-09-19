<?php

return [
    'driver' => env('NETWORK_DRIVER', 'fake'),
    'simulation' => env('NETWORK_DRIVER', 'fake') === 'fake',
    'discovery_provider' => env('NETWORK_DISCOVERY_PROVIDER', 'fake'),
    'discovery_freshness_seconds' => (int) env('NETWORK_DISCOVERY_FRESHNESS_SECONDS', 86400),
    'mutations_enabled' => filter_var(env('NETWORK_MUTATIONS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    /*
    | Roles allowed to dispatch network (RouterOS / PPPoE) operations.
    |
    | This is a capability allowlist, not a data-isolation boundary: tenant
    | membership is still required by the policies. Any role that is missing,
    | empty, or not listed here fails closed.
    */
    'operator_roles' => array_values(array_unique(array_filter(
        array_map('trim', explode(',', (string) env('NETWORK_OPERATOR_ROLES', 'owner,admin'))),
        fn ($role) => $role !== ''
    ))),
    'routeros' => [
        'transport' => env('ROUTEROS_DISCOVERY_TRANSPORT', 'api_ssl'),
        'connect_timeout_seconds' => (int) env('ROUTEROS_DISCOVERY_CONNECT_TIMEOUT_SECONDS', 3),
        'read_timeout_seconds' => (int) env('ROUTEROS_DISCOVERY_READ_TIMEOUT_SECONDS', 5),
        'insecure_tls' => env('ROUTEROS_DISCOVERY_INSECURE_TLS', false),
    ],
    'go' => [
        'url' => env('GO_NETWORK_ENGINE_URL', 'http://127.0.0.1:8787'),
        'token' => env('GO_NETWORK_ENGINE_TOKEN', ''),
        'connect_timeout_seconds' => (int) env('GO_NETWORK_ENGINE_CONNECT_TIMEOUT_SECONDS', 2),
        'timeout_seconds' => (int) env('GO_NETWORK_ENGINE_TIMEOUT_SECONDS', 10),
    ],
];

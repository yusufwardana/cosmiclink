<?php

return [
    'driver' => env('NETWORK_DRIVER', 'fake'),
    'simulation' => env('NETWORK_DRIVER', 'fake') === 'fake',
    'go' => [
        'url' => env('GO_NETWORK_ENGINE_URL', 'http://127.0.0.1:8787'),
        'token' => env('GO_NETWORK_ENGINE_TOKEN', ''),
        'connect_timeout_seconds' => (int) env('GO_NETWORK_ENGINE_CONNECT_TIMEOUT_SECONDS', 2),
        'timeout_seconds' => (int) env('GO_NETWORK_ENGINE_TIMEOUT_SECONDS', 10),
    ],
];

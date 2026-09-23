<?php

/**
 * Read-only verification: Laravel -> Go Network Engine -> RouterOS API.
 *
 * Prints only normalized, non-secret monitoring fields. Credentials are never
 * echoed; the Go client strips password/secret/token/credential fields.
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Router;
use App\Services\Network\GoNetworkMonitoringClient;

$routerId = (int) ($argv[1] ?? 2);
$router = Router::find($routerId);

if (! $router) {
    echo "Router {$routerId} not found." . PHP_EOL;
    exit(1);
}

echo "== Monitoring driver config ==" . PHP_EOL;
echo 'MONITORING_DRIVER: ' . config('monitoring.driver') . PHP_EOL;
echo 'monitoring.simulation: ' . (config('monitoring.simulation') ? 'true' : 'false') . PHP_EOL;
echo 'routeros transport: ' . config('network.routeros.transport') . PHP_EOL;
echo 'go engine url: ' . config('network.go.url') . PHP_EOL;
echo PHP_EOL;

echo "== Target ==" . PHP_EOL;
echo "router id: {$router->id} ({$router->name})" . PHP_EOL;
echo "host: {$router->host}:{$router->api_port}" . PHP_EOL;
echo 'observer_migration_state: ' . $router->observer_migration_state . PHP_EOL;
echo 'has encrypted credentials: ' . ($router->encrypted_credentials ? 'yes' : 'no') . PHP_EOL;
echo PHP_EOL;

echo "== Go engine collect ==" . PHP_EOL;
$result = app(GoNetworkMonitoringClient::class)->collect($router);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
echo PHP_EOL;

$reachable = ($result['reachable'] ?? false) === true;
echo 'reachable: ' . ($reachable ? 'YES' : 'NO') . PHP_EOL;
if (! $reachable) {
    echo 'failure: ' . json_encode($result['failure'] ?? null) . PHP_EOL;
    exit(2);
}

foreach (['identity', 'version', 'architecture', 'board', 'uptime', 'cpu_load_percent', 'memory_total_bytes', 'memory_free_bytes'] as $key) {
    echo "{$key}: " . json_encode($result[$key] ?? null) . PHP_EOL;
}
echo 'ppp_active_count: ' . count($result['ppp_active'] ?? []) . PHP_EOL;
echo 'collected_at: ' . json_encode($result['collected_at'] ?? null) . PHP_EOL;

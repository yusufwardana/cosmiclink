<?php

/**
 * Read-only observation runner: persists one real health observation for a
 * router through the same MonitoringService path the UI uses.
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Router;
use App\Models\User;
use App\Services\Monitoring\MonitoringService;

$routerId = (int) ($argv[1] ?? 2);
$router = Router::findOrFail($routerId);
$user = User::where('tenant_id', $router->tenant_id)->orderBy('id')->firstOrFail();

$observation = app(MonitoringService::class)->observeRouter($router, $user);

echo "== Persisted health observation ==" . PHP_EOL;
echo "id: {$observation->id}" . PHP_EOL;
echo "subject: {$observation->subject_type}:{$observation->subject_id} ({$router->name})" . PHP_EOL;
echo "health_state: {$observation->health_state}" . PHP_EOL;
echo 'reachable: ' . var_export($observation->reachable, true) . PHP_EOL;
echo 'online: ' . var_export($observation->online, true) . PHP_EOL;
echo "provider: {$observation->provider}" . PHP_EOL;
echo "observed_at: {$observation->observed_at}" . PHP_EOL;
echo 'metadata: ' . json_encode($observation->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

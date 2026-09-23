<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Router;
use App\Models\NetworkDiscoverySnapshot;

header('Content-Type: application/json');

$router = Router::find(2);

$latestSnapshot = $router->discoverySnapshots()
    ->where('status', 'success')
    ->latest('discovered_at')
    ->first();

if ($latestSnapshot) {
    echo json_encode([
        'snapshot_id' => $latestSnapshot->id,
        'discovered_at' => $latestSnapshot->discovered_at,
        'provider' => $latestSnapshot->provider,
        'summary' => $latestSnapshot->summary,
        'device' => $latestSnapshot->snapshot['device'] ?? null,
        'resource_count' => $latestSnapshot->resources()->count(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['error' => 'No successful snapshot found for router 2']);
}
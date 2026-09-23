<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Router;
use Illuminate\Support\Facades\DB;

echo "=== Direct Client Test ===" . PHP_EOL . PHP_EOL;

$router = Router::find(2);
if (!$router) {
    echo "ERROR: Router 2 not found!\n";
    exit(1);
}

echo "Router details:\n";
echo "  ID: {$router->id}\n";
echo "  Name: {$router->name}\n";
echo "  Host: {$router->host}:{$router->api_port}\n";
echo "  Username field: " . ($router->username ?? 'N/A') . "\n";
echo "  Migration state: {$router->observer_migration_state}\n";
echo "  Encrypted creds length: " . (strlen($router->encrypted_credentials ?? '') ?: 0) . "\n";
echo "  Agent ref: {$router->observer_agent_ref}\n";
echo "  Cred ref: {$router->observer_credential_ref}\n\n";

$client = new \App\Services\Network\GoNetworkMonitoringClient();
try {
    $payload = $client->collect($router);
    echo "Result:\n";
    echo "  reachable=" . json_encode($payload['reachable'] ?? false) . "\n";
    
    if (isset($payload['identity'])) {
        echo "  identity={$payload['identity']}\n";
    }
    if (isset($payload['version'])) {
        echo "  version={$payload['version']}\n";
    }
    
    if (!$payload['reachable']) {
        echo "  failure_code=" . json_encode($payload['failure']['code'] ?? 'UNKNOWN') . "\n";
        echo "  failure_message=" . json_encode($payload['failure']['message'] ?? 'N/A') . "\n";
    }
} catch (\Throwable $e) {
    echo "Exception: " . $e->getMessage() . "\n";
    echo "at line {$e->getLine()}\n";
}

<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Monitoring\TrafficCollectionService;
use App\Models\Router;

echo "=== Traffic Collection Diagnostics for Router 2 ===\n\n";

// Check router state
$router = Router::find(2);
if (!$router) {
    echo "ERROR: Router 2 not found!\n";
    exit(1);
}

echo "Router details:\n";
echo "  ID: {$router->id}\n";
echo "  Name: {$router->name}\n";
echo "  Host: {$router->host}:{$router->api_port}\n";
echo "  Migration state: {$router->observer_migration_state}\n";
echo "  Has encrypted creds: " . (empty($router->encrypted_credentials) ? 'NO' : 'YES') . "\n";
echo "  Username field: " . ($router->username ?? 'N/A') . "\n\n";

// Get encryption service
$key = config('app.key');
try {
    $decrypted = \Illuminate\Support\Facades\Crypt::decryptString($router->encrypted_credentials);
    echo "Decrypted credentials available: YES (length: " . strlen($decrypted) . ")\n";
} catch (\Exception $e) {
    echo "Decrypted credentials available: NO - " . $e->getMessage() . "\n";
}

// Initialize traffic collection service
$service = new TrafficCollectionService(new \App\Services\Network\GoNetworkMonitoringClient());

// Enable debug mode by temporarily setting logging
$config = [
    'driver' => env('MONITORING_DRIVER', 'fake'),
    'traffic_enabled' => filter_var(env('MONITORING_TRAFFIC_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
];

echo "\nConfiguration:\n";
echo "  MONITORING_DRIVER: {$config['driver']}\n";
echo "  TRAFFIC_ENABLED: " . ($config['traffic_enabled'] ? 'YES' : 'NO') . "\n\n";

if (!$config['traffic_enabled']) {
    echo "WARNING: Traffic collection is DISABLED in environment.\n";
    echo "Run this command first: php artisan tinker --execute=\"putenv('MONITORING_TRAFFIC_ENABLED=true');\"\\n\\n";
}

// Try to collect
echo "Attempting direct collection for Router 2...\n";
try {
    $result = $service->collectRouter($router);
    
    if ($result) {
        echo "✅ SUCCESS! Collection created:\n";
        echo "  ID: {$result->id}\n";
        echo "  Collected at: {$result->collected_at}\n";
        echo "  Datasets: " . json_encode($result->datasets) . "\n";
    } else {
        echo "❌ FAILED: No collection returned\n";
        
        // Test client directly
        echo "\nTesting client connection directly...\n";
        $client = new \App\Services\Network\GoNetworkMonitoringClient();
        $payload = $client->collect($router);
        
        echo "Client result:\n";
        echo "  reachable: " . json_encode($payload['reachable'] ?? false) . "\n";
        
        if (!$payload['reachable'] && isset($payload['failure'])) {
            echo "  failure_code: " . json_encode($payload['failure']['code'] ?? 'UNKNOWN') . "\n";
            echo "  failure_message: " . json_encode($payload['failure']['message'] ?? 'N/A') . "\n";
        }
    }
} catch (\Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    echo "at line {$e->getLine()}\n";
}

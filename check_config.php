<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Router id=2 in cosmiclink database:" . PHP_EOL;
$router = DB::table('routers')->where('id', 2)->first();
echo json_encode($router, JSON_PRETTY_PRINT) . PHP_EOL;

if (!$router->observer_credential_ref || $router->observer_migration_state !== 'LOCAL_OBSERVER_ACTIVE') {
    echo PHP_EOL . "=== BINDING OBSERVER CREDENTIAL REFERENCE ===" . PHP_EOL;
    
    // Bind verified OBSERVER credential from hardware acceptance
    DB::table('routers')->where('id', 2)->update([
        'observer_migration_state' => 'LOCAL_OBSERVER_ACTIVE',
        'observer_agent_ref' => 'be1d14bf-405c-4223-ac47-625a76f8c50c',
        'observer_installation_id' => '8b902fd9-902b-4c7a-b4e1-a69f947ecd2d',
        'observer_credential_ref' => '2308170a-0dfc-452f-9088-ded13340fc10',
        'observer_credential_purpose' => 'OBSERVER',
        'observer_credential_version' => 1,
        'observer_credential_status' => 'ACTIVE',
        'observer_reference_bound_at' => now(),
    ]);
    
    echo "✅ Bound successfully!" . PHP_EOL;
} else {
    echo "✅ Already bound." . PHP_EOL;
}

// Re-check
$newRouter = DB::table('routers')->where('id', 2)->first();
echo PHP_EOL . "Updated router:" . PHP_EOL;
echo json_encode($newRouter, JSON_PRETTY_PRINT) . PHP_EOL;


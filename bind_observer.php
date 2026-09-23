<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// Router ID for MikroTik hEX (not demo router)
$routerId = 2;
$router = DB::table('routers')->where('id', $routerId)->first();

if (!$router) {
    echo "Router {$routerId} not found!" . PHP_EOL;
    exit(1);
}

echo "Updating Router ID {$routerId} ({$router->name})..." . PHP_EOL;

// Bind verified OBSERVER credential references from hardware acceptance
// These match the agent-data-fresh-2acc02e8d92c4c7a98f0d6b77ab45c48 directory
DB::table('routers')->where('id', $routerId)->update([
    'observer_migration_state' => 'LOCAL_OBSERVER_ACTIVE',
    'observer_agent_ref' => 'be1d14bf-405c-4223-ac47-625a76f8c50c',
    'observer_installation_id' => '8b902fd9-902b-4c7a-b4e1-a69f947ecd2d',
    'observer_credential_ref' => '2308170a-0dfc-452f-9088-ded13340fc10',
    'observer_credential_purpose' => 'OBSERVER',
    'observer_credential_version' => 1,
    'observer_credential_status' => 'ACTIVE',
    'observer_reference_bound_at' => now(),
]);

echo "✅ Observer references bound successfully." . PHP_EOL;

// Verify
$updated = DB::table('routers')->where('id', $routerId)->first();
echo PHP_EOL . "Verification:" . PHP_EOL;
echo "  state={$updated->observer_migration_state}" . PHP_EOL;
echo "  agent_ref=" . $updated->observer_agent_ref . PHP_EOL;
echo "  installation_id=" . $updated->observer_installation_id . PHP_EOL;
echo "  credential_ref=" . $updated->observer_credential_ref . PHP_EOL;
echo "  purpose={$updated->observer_credential_purpose}" . PHP_EOL;
echo "  version={$updated->observer_credential_version}" . PHP_EOL;

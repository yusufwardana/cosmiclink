<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Switching Router ID 2 to LEGACY mode for inline credential usage...\n\n";

DB::table('routers')
    ->where('id', 2)
    ->update([
        'observer_migration_state' => 'LEGACY',
    ]);

$router = DB::table('routers')->where('id', 2)->first();
echo "Updated migration state: {$router->observer_migration_state}\n";
echo "\nRouter will now use inline encrypted_credentials instead of Agent store.\n";

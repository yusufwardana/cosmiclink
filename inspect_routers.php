<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== ROUTERS ===" . PHP_EOL;
$routers = DB::table('routers')->select('id', 'name', 'host', 'api_port', 'username', 'status', 'driver', 'observer_migration_state', 'observer_agent_ref', 'observer_installation_id', 'observer_credential_ref', 'observer_credential_purpose', 'observer_credential_version', 'encrypted_credentials')->orderBy('id')->get();

foreach ($routers as $r) {
    echo "Router id={$r->id}, name={$r->name}, host={$r->host}, port={$r->api_port}" . PHP_EOL;
    echo "  state={$r->observer_migration_state}" . PHP_EOL;
    echo "  agent_ref=" . $r->observer_agent_ref . PHP_EOL;
    echo "  installation_id=" . $r->observer_installation_id . PHP_EOL;
    echo "  credential_ref=" . $r->observer_credential_ref . PHP_EOL;
    echo "  purpose=" . $r->observer_credential_purpose . PHP_EOL;
    echo "  version=" . $r->observer_credential_version . PHP_EOL;
    echo "  has_cred=" . ($r->encrypted_credentials ? 'YES' : 'NO') . PHP_EOL;
    echo PHP_EOL;
}

echo "=== USERS WITH TENANT ===" . PHP_EOL;
$users = DB::table('users')->select('id', 'email', 'role', 'tenant_id')->whereNotNull('tenant_id')->get();
foreach ($users as $u) {
    echo "User {$u->id}: {$u->email} ({$u->role}) tenant={$u->tenant_id}" . PHP_EOL;
}

<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$dbs = DB::select('select datname from pg_database where datistemplate = false order by datname');
foreach ($dbs as $d) {
    $db = $d->datname;
    if ($db === 'postgres') { continue; }
    echo "[{$db}]", PHP_EOL;
    $env = [
        'host' => config('database.connections.pgsql.host'),
        'port' => config('database.connections.pgsql.port'),
        'database' => $db,
        'username' => config('database.connections.pgsql.username'),
        'password' => config('database.connections.pgsql.password'),
        'driver' => 'pgsql',
        'prefix' => '',
        'schema' => 'public',
        'sslmode' => 'prefer',
    ];
    config(['database.connections.inspect_db' => $env]);
    DB::purge('inspect_db');
    try {
        $routers = DB::connection('inspect_db')->table('routers')->select('id', 'name', 'host', 'api_port', 'username', 'status', 'driver', 'observer_migration_state', 'observer_agent_ref', 'observer_credential_ref', 'last_seen_at')->orderBy('id')->get();
        foreach ($routers as $r) { echo '  router: ', json_encode($r, JSON_UNESCAPED_SLASHES), PHP_EOL; }
        $agents = DB::connection('inspect_db')->table('network_agents')->select('id', 'identifier', 'name', 'version', 'last_seen_at', 'observed_health', 'token_id')->orderBy('id')->get();
        foreach ($agents as $a) { echo '  agent: ', json_encode($a, JSON_UNESCAPED_SLASHES), PHP_EOL; }
        foreach (['health_observations', 'traffic_collections', 'traffic_samples', 'traffic_buckets', 'network_agent_jobs'] as $t) {
            try { $c = DB::connection('inspect_db')->table($t)->count(); echo "  {$t}: {$c}", PHP_EOL; }
            catch (Throwable $e) { echo "  {$t}: MISSING", PHP_EOL; }
        }
    } catch (Throwable $e) {
        echo '  error: ', $e->getMessage(), PHP_EOL;
    }
}

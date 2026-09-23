<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$out = static function (string $title, array $rows): void {
    echo PHP_EOL . '== ' . $title . ' ==' . PHP_EOL;
    foreach ($rows as $row) {
        echo json_encode($row, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
};

$out('drivers/config', [[
    'NETWORK_DRIVER' => config('network.driver'),
    'simulation' => config('network.simulation'),
    'MONITORING_DRIVER' => env('MONITORING_DRIVER'),
    'DISCOVERY' => config('network.discovery_provider'),
    'MUTATION_PROVIDER' => config('network.mutation_provider'),
    'MUTATIONS_ENABLED' => config('network.mutations_enabled'),
    'GO_URL' => config('network.go.url'),
    'GO_TOKEN_SET' => config('network.go.token') !== '',
    'APP_ENV' => config('app.env'),
]]);

$out('tenants', DB::table('tenants')->get(['id', 'name', 'slug'])->toArray());
$out('users', DB::table('users')->get(['id', 'tenant_id', 'email', 'role'])->toArray());
$out('routers', DB::table('routers')->get([
    'id', 'tenant_id', 'name', 'host', 'api_port', 'username', 'status', 'driver',
    'last_seen_at', 'monitoring_state',
    'observer_agent_ref', 'observer_installation_id', 'observer_credential_ref',
    'observer_credential_purpose', 'observer_credential_status', 'observer_migration_state',
    'observer_reference_bound_at', 'observer_reference_synced_at',
    'observer_synced_agent_ref', 'observer_synced_credential_status',
])->map(function ($r) { $r->{'(has_credentials)'} = $r->encrypted_credentials ?? null; return (function ($o) { unset($o->encrypted_credentials); return $o; })($r); })->toArray());
$out('routers_cred_flag', DB::table('routers')->get(['id', DB::raw('length(encrypted_credentials) as cred_len')])->toArray());
$out('network_agents', DB::table('network_agents')->get(['id', 'tenant_id', 'identifier', 'name', 'version', 'capabilities', 'metadata', 'last_seen_at', 'observed_health', 'token_id'])->toArray());
$out('counts', [[
    'health_observations' => DB::table('health_observations')->count(),
    'health_obs_routers' => DB::table('health_observations')->where('subject_type', 'router')->count(),
    'network_operation_logs' => DB::table('network_operation_logs')->count(),
    'traffic_collections' => DB::table('traffic_collections')->count(),
    'traffic_samples' => DB::table('traffic_samples')->count(),
    'traffic_buckets' => DB::table('traffic_buckets')->count(),
    'discovery_snapshots' => DB::table('network_discovery_snapshots')->count(),
]]);
$out('health_latest', DB::table('health_observations')->select('subject_type', 'subject_id', 'health_state', 'observed_at', 'source')->latest('observed_at')->limit(10)->get()->toArray());
$out('traffic_buckets_by_source', DB::table('traffic_buckets')->select('source_type', DB::raw('count(*) c'), DB::raw('max(bucket_started_at) latest'))->groupBy('source_type')->get()->toArray());
$out('traffic_collections_recent', DB::table('traffic_collections')->select('router_id', 'status', 'started_at', 'finished_at', 'error_code')->latest('id')->limit(10)->get()->toArray());
$out('agent_jobs_recent', DB::table('network_agent_jobs')->select('id', 'agent_id', 'type', 'status', 'created_at', 'error_code')->latest('id')->limit(10)->get()->toArray());

echo PHP_EOL . 'DONE' . PHP_EOL;

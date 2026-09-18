<?php

use App\Models\NetworkAgent;
use App\Models\NetworkAgentJob;
use App\Models\Router;
use App\Models\Tenant;
use App\Services\Network\NetworkAgentHealthService;
use App\Services\Network\NetworkAgentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Test-only process bridge. JSON stdout is consumed privately by the acceptance
// harness, never written to logs. Refuse any non-isolated database.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.connections.pgsql.database') !== 'cosmiclink_test') {
    throw new RuntimeException('Acceptance requires cosmiclink_test.');
}
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$service = app(NetworkAgentService::class);
$tenant = isset($input['tenant']) ? Tenant::findOrFail($input['tenant']) : null;
$output = [];
switch ($input['action']) {
    case 'enroll':
        $tenant = Tenant::factory()->create();
        $router = Router::factory()->for($tenant)->create(['host' => '127.0.0.1']);
        $router->setPassword($input['router_secret']);
        $router->save();
        [$agent, $token] = $service->enroll($tenant, 'Acceptance Agent');
        $output = ['tenant' => $tenant->id, 'agent' => $agent->id, 'router' => $router->id, 'token' => $token];
        break;
    case 'create':
        $agent = NetworkAgent::where('tenant_id', $tenant->id)->findOrFail($input['agent']);
        $router = Router::where('tenant_id', $tenant->id)->findOrFail($input['router']);
        $job = $service->createDiscoveryJob($router, $agent, null);
        $output = ['job' => $job->id, 'status' => $job->status];
        break;
    case 'state':
        $agent = NetworkAgent::where('tenant_id', $tenant->id)->findOrFail($input['agent']);
        $output = ['health' => app(NetworkAgentHealthService::class)->health($agent), 'last_seen' => $agent->last_seen_at?->toISOString()];
        foreach (['network_discovery_snapshots', 'discovered_network_resources', 'network_discovery_audits', 'network_agent_events', 'network_agent_job_attempts', 'network_operation_logs'] as $table) {
            $output[$table] = DB::table($table)->where('tenant_id', $tenant->id)->count();
        }
        $output['events'] = DB::table('network_agent_events')->where('tenant_id', $tenant->id)->orderBy('id')->pluck('type');
        $output['managed'] = DB::table('discovered_network_resources')->where('tenant_id', $tenant->id)->where('management_state', 'MANAGED')->count();
        if (isset($input['job'])) {
            $job = NetworkAgentJob::where('tenant_id', $tenant->id)->findOrFail($input['job']);
            $output['job'] = $job->only(['status', 'attempt', 'fence', 'lease_expires_at']);
        }
        break;
    case 'claim':
        $agent = NetworkAgent::where('tenant_id', $tenant->id)->findOrFail($input['agent']);
        $claim = $service->claim($agent);
        $output = ['claimed' => $claim !== null, 'attempt' => $claim['job']['attempt'] ?? null];
        break;
    case 'audit':
        $safe = true;
        foreach (['network_agents', 'network_agent_jobs', 'network_agent_events', 'network_agent_job_attempts', 'network_discovery_snapshots', 'discovered_network_resources', 'network_discovery_audits'] as $table) {
            $encoded = json_encode(DB::table($table)->where('tenant_id', $tenant->id)->get());
            foreach ($input['secrets'] as $secret) {
                $safe = $safe && ! str_contains($encoded, $secret);
            }
        }
        $log = storage_path('logs/laravel.log');
        if (is_file($log)) {
            $contents = file_get_contents($log);
            foreach ($input['secrets'] as $secret) {
                $safe = $safe && ! str_contains($contents, $secret);
            }
        }
        $output = ['safe' => $safe];
        break;
    case 'cleanup':
        $tenant->delete();
        break;
    default:
        throw new RuntimeException('Unknown acceptance operation.');
}
echo json_encode($output, JSON_THROW_ON_ERROR);

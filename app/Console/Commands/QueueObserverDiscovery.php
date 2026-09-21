<?php

namespace App\Console\Commands;

use App\Models\NetworkAgent;
use App\Models\NetworkAgentJob;
use App\Models\Router;
use App\Services\Network\NetworkAgentService;
use Illuminate\Console\Command;

class QueueObserverDiscovery extends Command
{
    protected $signature = 'network-agents:discover-observer
        {tenant : Tenant ID}
        {router : Router ID}
        {agent : Existing Agent identifier}
        {--confirm= : Must equal DISCOVER_OBSERVER <tenant> <router> <agent>}';

    protected $description = 'Queue one read-only RouterOS discovery job for an active local OBSERVER reference';

    public function handle(NetworkAgentService $agents): int
    {
        $tenant = (string) $this->argument('tenant');
        $routerId = (string) $this->argument('router');
        $agentRef = (string) $this->argument('agent');
        $expected = implode(' ', ['DISCOVER_OBSERVER', $tenant, $routerId, $agentRef]);

        if ((string) $this->option('confirm') !== $expected) {
            $this->error('Explicit read-only discovery confirmation is required.');

            return self::FAILURE;
        }

        $router = Router::query()->whereKey($routerId)->where('tenant_id', $tenant)->first();
        $agent = NetworkAgent::query()->where('identifier', $agentRef)->where('tenant_id', $tenant)->first();
        if (! $router || ! $agent) {
            $this->error('Tenant-scoped router or Agent not found.');

            return self::FAILURE;
        }

        if ($router->observer_migration_state !== 'LOCAL_OBSERVER_ACTIVE'
            || $router->observer_agent_ref !== $agent->identifier
            || $router->observer_credential_purpose !== 'OBSERVER'
            || $router->observer_credential_status !== 'ACTIVE') {
            $this->error('Router does not have an active local OBSERVER reference.');

            return self::FAILURE;
        }

        try {
            $job = $agents->createDiscoveryJob($router, $agent, null);
        } catch (\Throwable $exception) {
            $this->error('Read-only discovery job rejected.');

            return self::FAILURE;
        }

        $this->info('Read-only discovery queued: '.$job->id.' '.$job->status);

        return self::SUCCESS;
    }
}
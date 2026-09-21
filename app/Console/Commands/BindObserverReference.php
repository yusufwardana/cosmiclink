<?php

namespace App\Console\Commands;

use App\Models\NetworkAgent;
use App\Models\Router;
use App\Services\Network\ObserverReferenceService;
use Illuminate\Console\Command;

class BindObserverReference extends Command
{
    protected $signature = 'network-agents:bind-observer
        {tenant : Tenant ID}
        {router : Router ID}
        {agent : Existing Agent identifier}
        {credential : Existing OBSERVER credential reference}
        {installation : Existing Agent installation identity}
        {version : OBSERVER credential version}
        {--confirm= : Must equal BIND_OBSERVER <tenant> <router> <agent> <credential> <installation> <version>}';

    protected $description = 'Bind and explicitly activate an already-synchronized OBSERVER reference';

    public function handle(ObserverReferenceService $references): int
    {
        $tenant = (string) $this->argument('tenant');
        $routerId = (string) $this->argument('router');
        $agentRef = (string) $this->argument('agent');
        $credentialRef = (string) $this->argument('credential');
        $installationId = (string) $this->argument('installation');
        $version = (int) $this->argument('version');
        $expected = implode(' ', ['BIND_OBSERVER', $tenant, $routerId, $agentRef, $credentialRef, $installationId, $version]);

        if ((string) $this->option('confirm') !== $expected) {
            $this->error('Explicit observer binding confirmation is required.');

            return self::FAILURE;
        }

        $router = Router::query()->whereKey($routerId)->where('tenant_id', $tenant)->first();
        $agent = NetworkAgent::query()->where('identifier', $agentRef)->where('tenant_id', $tenant)->first();
        if (! $router || ! $agent) {
            $this->error('Tenant-scoped router or Agent not found.');

            return self::FAILURE;
        }

        $reference = [
            'tenant_ref' => $tenant,
            'router_ref' => $routerId,
            'agent_ref' => $agentRef,
            'installation_id' => $installationId,
            'credential_ref' => $credentialRef,
            'purpose' => 'OBSERVER',
            'version' => $version,
            'status' => 'ACTIVE',
        ];

        try {
            $references->bind($router, $agent, $reference);
            $router = $references->activate($router->fresh(), $agent);
        } catch (\Throwable $exception) {
            $this->error('Observer reference lifecycle rejected.');

            return self::FAILURE;
        }

        $this->info('Observer reference activated: '.$router->observer_migration_state);

        return self::SUCCESS;
    }
}
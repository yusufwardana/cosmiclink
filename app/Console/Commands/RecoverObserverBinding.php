<?php

namespace App\Console\Commands;

use App\Services\Network\ObserverBindingRecoveryService;
use Illuminate\Console\Command;

final class RecoverObserverBinding extends Command
{
    protected $signature = 'network-agents:recover-observer-binding
        {tenant : New Core tenant ID}
        {host : Physical router host}
        {port : Physical router API port}
        {installation : Preserved Agent installation identity}
        {credential : Preserved OBSERVER credential reference}
        {version : Preserved OBSERVER credential version}
        {old-tenant : Preserved old tenant reference}
        {old-router : Preserved old router reference}
        {--helper= : Local observer recovery helper executable}';

    protected $description = 'Recover an existing protected OBSERVER binding after Core database identity loss';

    public function handle(ObserverBindingRecoveryService $recovery): int
    {
        try {
            $result = $recovery->recover([
                'tenant_id' => (int) $this->argument('tenant'),
                'host' => (string) $this->argument('host'),
                'port' => (int) $this->argument('port'),
                'installation_id' => (string) $this->argument('installation'),
                'credential_ref' => (string) $this->argument('credential'),
                'version' => (int) $this->argument('version'),
                'old_tenant_ref' => (string) $this->argument('old-tenant'),
                'old_router_ref' => (string) $this->argument('old-router'),
                'helper' => $this->option('helper'),
            ]);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Observer binding recovery refused or rolled back.');

            return self::FAILURE;
        }
    }
}
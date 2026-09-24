<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Services\Network\DeviceObservationService;
use Illuminate\Console\Command;

final class EnrichDhcpObservations extends Command
{
    protected $signature = 'network:enrich-dhcp {router=1}';
    protected $description = 'Enrich existing device observations from one read-only DHCP lease survey';

    public function handle(DeviceObservationService $observations): int
    {
        try {
            $router = Router::query()->findOrFail((int) $this->argument('router'));
            abort_unless($router->observer_credential_purpose === 'OBSERVER' && $router->observer_credential_status === 'ACTIVE', 422);
            $result = $observations->enrichDhcp($router, $observations->collectDhcpLeases($router));
            $this->info('DHCP leases: '.$result['leases'].'; matched existing observations: '.$result['matched']);
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Read-only DHCP enrichment failed.');
            return self::FAILURE;
        }
    }
}
<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Services\Network\DeviceObservationService;
use Illuminate\Console\Command;

final class CollectDeviceObservations extends Command
{
    protected $signature = 'network:observe-devices {router=1}';
    protected $description = 'Collect one read-only ARP and Hotspot device observation for a router';

    public function handle(DeviceObservationService $observations): int
    {
        try {
            $router = Router::query()->findOrFail((int) $this->argument('router'));
            abort_unless($router->observer_credential_purpose === 'OBSERVER' && $router->observer_credential_status === 'ACTIVE', 422);
            $survey = $observations->collect($router);
            $result = $observations->persist($router, $survey);
            $this->info('Device observations persisted: '.$result['observed']);
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Read-only device observation collection failed.');
            return self::FAILURE;
        }
    }
}
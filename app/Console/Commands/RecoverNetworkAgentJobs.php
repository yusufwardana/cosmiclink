<?php

namespace App\Console\Commands;

use App\Services\Network\NetworkAgentService;
use Illuminate\Console\Command;

class RecoverNetworkAgentJobs extends Command
{
    protected $signature = 'network-agents:recover-jobs';

    protected $description = 'Recover expired read-only Agent leases and reconcile health transitions';

    public function handle(NetworkAgentService $agents): int
    {
        $this->info('Recovered jobs: '.$agents->recover());

        return self::SUCCESS;
    }
}

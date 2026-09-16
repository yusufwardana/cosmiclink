<?php

namespace App\Console\Commands;

use App\Actions\ProcessOverdueBilling;
use App\Models\Tenant;
use Illuminate\Console\Command;

class EnforceBillingCommand extends Command
{
    protected $signature = 'billing:enforce';

    protected $description = 'Apply overdue billing isolation for every tenant.';

    public function handle(ProcessOverdueBilling $process): int
    {
        $count = 0;
        Tenant::with('users')->each(function ($tenant) use ($process, &$count): void {
            $user = $tenant->users->first();
            if ($user) {
                $count += $process->handle($tenant->id, $user);
            }
        });
        $this->info("Processed {$count} connection(s).");

        return self::SUCCESS;
    }
}

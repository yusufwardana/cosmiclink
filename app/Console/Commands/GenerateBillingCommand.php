<?php

namespace App\Console\Commands;

use App\Actions\GenerateMonthlyInvoices;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerateBillingCommand extends Command
{
    protected $signature = 'billing:generate {--period= : Month in YYYY-MM format}';

    protected $description = 'Generate tenant invoices for a monthly billing period.';

    public function handle(GenerateMonthlyInvoices $generate): int
    {
        $period = now()->startOfMonth();
        if ($this->option('period')) {
            $period = Carbon::createFromFormat('Y-m', $this->option('period'))->startOfMonth();
        } $count = 0;
        Tenant::query()->each(fn ($tenant) => $count += $generate->handle($tenant->id, $period));
        $this->info("Generated {$count} invoice(s).");

        return self::SUCCESS;
    }
}

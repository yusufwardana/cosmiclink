<?php

use App\Services\Monitoring\MonitoringService;
use App\Services\Monitoring\TrafficCollectionService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('network-agents:recover-jobs')->everyMinute()->withoutOverlapping();
Schedule::command('monitoring:run')->everyMinute()->withoutOverlapping();
Schedule::command('monitoring:prune-observations')->daily()->withoutOverlapping();
Schedule::command('monitoring:collect-traffic')->everyMinute()->withoutOverlapping();
Schedule::command('monitoring:prune-traffic')->daily()->withoutOverlapping();

Artisan::command('monitoring:run', function (MonitoringService $monitoring) {
    $this->info('Monitoring observations written: '.$monitoring->runScheduled());
})->purpose('Collect read-only router and PPPoE health observations');

Artisan::command('monitoring:prune-observations', function (MonitoringService $monitoring) {
    $this->info('Pruned observations: '.$monitoring->prune());
})->purpose('Prune expired health observations');

Artisan::command('monitoring:collect-traffic', function (TrafficCollectionService $traffic) {
    $this->info('Traffic collections written: '.$traffic->runScheduled());
})->purpose('Collect read-only RouterOS traffic counters');

Artisan::command('monitoring:prune-traffic', function (TrafficCollectionService $traffic) {
    $pruned = $traffic->prune();
    $this->info("Pruned traffic collections: {$pruned['collections']}; buckets: {$pruned['buckets']}");
})->purpose('Prune expired traffic samples and buckets');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

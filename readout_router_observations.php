<?php

/**
 * Throwaway readout: latest router observations per subject.
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\HealthObservation;
use App\Models\Router;

foreach (HealthObservation::where('subject_type', 'router')->latest('observed_at')->get()->unique('subject_id') as $observation) {
    $router = Router::find($observation->subject_id);
    echo "router {$observation->subject_id} ({$router?->name})" . PHP_EOL;
    echo "  health_state: {$observation->health_state}" . PHP_EOL;
    echo '  reachable: ' . var_export($observation->reachable, true) . PHP_EOL;
    echo "  provider: {$observation->provider}" . PHP_EOL;
    echo "  observed_at: {$observation->observed_at}" . PHP_EOL;
    echo '  metadata: ' . json_encode($observation->metadata, JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

echo 'freshness_seconds: ' . config('monitoring.freshness_seconds') . PHP_EOL;
echo 'monitoring.driver: ' . config('monitoring.driver') . PHP_EOL;

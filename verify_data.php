<?php

// Load .env.local FIRST before any Laravel bootstrap
$dotenvFile = __DIR__ . '/.env.local';
if (file_exists($dotenvFile)) {
    $lines = file($dotenvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && !preg_match('/^\s*#/', $line)) {
            list($key, $value) = explode('=', $line, 2);
            putenv(trim($key) . '=' . trim($value));
        }
    }
}

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== HEALTH OBSERVATIONS (Routers) ===" . PHP_EOL;
$observations = DB::table('health_observations')
    ->where('subject_type', 'router')
    ->select('id', 'subject_id', 'health_state', 'provider', 'observed_at')
    ->orderBy('observed_at', 'desc')
    ->limit(5)
    ->get();

foreach ($observations as $o) {
    echo "Router {$o->subject_id}: {$o->health_state} via {$o->provider} at {$o->observed_at}" . PHP_EOL;
}

echo PHP_EOL . "=== TRAFFIC COLLECTIONS ===" . PHP_EOL;
$collections = DB::table('traffic_collections')
    ->select('id', 'router_id', 'provider', 'datasets', 'collected_at')
    ->orderBy('collected_at', 'desc')
    ->limit(5)
    ->get();

if ($collections->isEmpty()) {
    echo "No traffic collections yet." . PHP_EOL;
} else {
    foreach ($collections as $c) {
        echo "Collection id={$c->id}, router={$c->router_id}, provider={$c->provider}" . PHP_EOL;
        echo "  datasets=" . json_encode($c->datasets) . PHP_EOL;
        echo "  collected_at={$c->collected_at}" . PHP_EOL;
    }
}

echo PHP_EOL . "=== TRAFFIC SAMPLES COUNT ===" . PHP_EOL;
$samples = DB::table('traffic_samples')->count();
echo "Total samples in database: {$samples}" . PHP_EOL;

echo PHP_EOL . "=== TRAFFIC BUCKETS COUNT ===" . PHP_EOL;
$buckets = DB::table('traffic_buckets')->count();
echo "Total buckets in database: {$buckets}" . PHP_EOL;

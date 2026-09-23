<?php

/**
 * Throwaway web-context config probe (read-only). Delete after diagnosis.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

header('Content-Type: text/plain; charset=utf-8');

echo 'APP_ENV (env): ' . var_export(env('APP_ENV'), true) . PHP_EOL;
echo 'getenv APP_ENV: ' . var_export(getenv('APP_ENV'), true) . PHP_EOL;
echo 'getenv MONITORING_DRIVER: ' . var_export(getenv('MONITORING_DRIVER'), true) . PHP_EOL;
echo 'getenv NETWORK_DRIVER: ' . var_export(getenv('NETWORK_DRIVER'), true) . PHP_EOL;
echo 'config monitoring.driver: ' . var_export(config('monitoring.driver'), true) . PHP_EOL;
echo 'config monitoring.simulation: ' . var_export(config('monitoring.simulation'), true) . PHP_EOL;
echo 'config network.driver: ' . var_export(config('network.driver'), true) . PHP_EOL;
echo 'config network.simulation: ' . var_export(config('network.simulation'), true) . PHP_EOL;
echo 'config network.routeros.transport: ' . var_export(config('network.routeros.transport'), true) . PHP_EOL;
echo 'runningInConsole: ' . var_export($app->runningInConsole(), true) . PHP_EOL;
echo 'environmentPath: ' . var_export($app->environmentPath(), true) . PHP_EOL;
echo 'environmentFile: ' . var_export($app->environmentFile(), true) . PHP_EOL;
echo '.env.local exists: ' . var_export(is_file($app->environmentPath().'/.env.local'), true) . PHP_EOL;
echo '.env.local readable: ' . var_export(is_readable($app->environmentPath().'/.env.local'), true) . PHP_EOL;
echo '.env exists: ' . var_export(is_file($app->environmentPath().'/.env'), true) . PHP_EOL;
echo 'Env::get APP_ENV: ' . var_export(\Illuminate\Support\Env::get('APP_ENV'), true) . PHP_EOL;
echo '_ENV MONITORING_DRIVER: ' . var_export($_ENV['MONITORING_DRIVER'] ?? null, true) . PHP_EOL;

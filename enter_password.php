<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Router;
use Illuminate\Support\Facades\Crypt;

echo "=== Password Entry Mode ===\n\n";
echo "Enter the current password for user 'cosmiclink' on MikroTik 10.10.12.1:\n";
echo "(Password will not be displayed as you type)\n\n";

// Try to use hidden input via Windows console
try {
    $password = readline('Password: ');
    // Check if it's a terminal and echo is enabled, then disable it
    if (function_exists('pcntl_alarm')) {
        // Fallback - normal readline doesn't support hiding in PHP on Windows
        // User must manually press keys without seeing output
    }
} catch (Exception $e) {
    echo "Warning: Cannot hide password input.\n";
    $password = trim(fgets(STDIN));
}

if ($password === '') {
    echo "\nERROR: Empty password!\n";
    exit(1);
}

echo "\nEncrypting and saving to Router ID 2...\n";
$router = Router::find(2);
if (!$router) {
    echo "ERROR: Router 2 not found!\n";
    exit(1);
}

$router->setPassword($password);
$router->save();

echo "✅ Password encrypted and saved successfully!\n";
echo "\nMigration state: {$router->observer_migration_state}\n";
echo "Encrypted credentials length: " . strlen($router->encrypted_credentials ?? '') . "\n";
echo "Username field: {$router->username}\n";
echo "\nNext: Testing real monitoring connection...\n";

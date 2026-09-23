<?php

echo "Generating local runtime token..." . PHP_EOL;
$token = bin2hex(random_bytes(32));
file_put_contents('.env.local.token', $token);
echo "Token generated and stored in .env.local.token (DO NOT COMMIT)" . PHP_EOL . PHP_EOL;

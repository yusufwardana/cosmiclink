<?php

namespace App\Services\Network;

class DiscoveryResult
{
    public function __construct(public readonly bool $successful, public readonly string $message, public readonly ?string $errorCode = null, public readonly array $data = []) {}
}

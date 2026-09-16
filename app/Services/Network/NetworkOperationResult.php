<?php

namespace App\Services\Network;

final readonly class NetworkOperationResult
{
    public function __construct(
        public bool $successful,
        public string $message,
        public ?string $errorCode = null,
        public array $data = [],
    ) {}
}

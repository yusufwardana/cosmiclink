<?php

namespace App\Services\Network;

final readonly class ControlledNetworkOperationDecision
{
    public function __construct(
        public bool $allowed,
        public ?string $errorCode = null,
    ) {}

    public static function allow(): self
    {
        return new self(true);
    }

    public static function deny(string $errorCode): self
    {
        return new self(false, $errorCode);
    }
}

<?php

namespace App\Services\Network;

final readonly class RouterCompatibilityDecision
{
    public function __construct(
        public bool $compatible,
        public string $reason,
        public string $target,
        public string $configuredProvider,
        public string $executionMode,
        public bool $realProviderCompatible,
        public ?int $snapshotId = null,
        public ?string $routerOsVersion = null,
    ) {}

    public function canAuthorizeRealMutation(): bool
    {
        return $this->compatible && $this->realProviderCompatible;
    }
}

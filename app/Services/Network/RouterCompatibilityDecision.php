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
        public bool $architectureSupported = false,
        public bool $hardwareAccepted = false,
        public ?int $snapshotId = null,
        public ?string $routerOsVersion = null,
        public ?string $rawRouterOsVersion = null,
        public ?string $observedIdentity = null,
    ) {}

    public function canAuthorizeRealMutation(): bool
    {
        return $this->compatible && $this->realProviderCompatible && $this->hardwareAccepted;
    }
}

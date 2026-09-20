<?php

namespace App\Services\Network;

final readonly class ControlledNetworkExecution
{
    public function __construct(
        public string $operation,
        public string $idempotencyKey,
        public string $requestDigest,
        public string $executionId,
        public ?string $agentRef = null,
        public ?string $installationId = null,
        public ?string $credentialRef = null,
        public ?int $credentialVersion = null,
        public ?string $targetIdentityRef = null,
        public ?string $fencingRef = null,
        public ?string $observerAgentRef = null,
        public ?string $observerInstallationId = null,
        public ?string $observerCredentialRef = null,
        public ?int $observerCredentialVersion = null,
    ) {}
}

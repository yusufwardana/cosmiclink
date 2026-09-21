<?php

namespace App\Services\Network;

use App\Models\HealthObservation;
use App\Models\ManagedTargetPreflightEvidence;

final readonly class ManagedTargetPreflightResult
{
    public function __construct(
        public bool $resolved,
        public ?string $reasonCode = null,
        public ?ManagedTargetPreflightEvidence $evidence = null,
        public ?bool $hardwareDisabled = null,
        public ?HealthObservation $health = null,
    ) {}
}

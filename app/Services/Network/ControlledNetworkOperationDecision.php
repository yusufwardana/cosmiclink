<?php

namespace App\Services\Network;

final readonly class ControlledNetworkOperationDecision
{
    /**
     * @param  array<string, mixed>  $evidence  Safe, bounded projections that explain the
     *                                          decision. Never provider payloads, never
     *                                          credentials, never plaintext confirmations.
     */
    public function __construct(
        public bool $allowed,
        public ?string $errorCode = null,
        /** Which ordered lifecycle precondition (PRE1-PRE12) this denial is attributed to. */
        public ?string $failedPrecondition = null,
        public array $evidence = [],
    ) {}

    public static function allow(): self
    {
        return new self(true);
    }

    public static function deny(string $errorCode, ?string $failedPrecondition = null): self
    {
        return new self(false, $errorCode, $failedPrecondition);
    }

    /**
     * Return a copy of this denial carrying additional safety evidence. Evidence
     * explains *what is still missing*; it never widens what the gate allows.
     *
     * @param  array<string, mixed>  $evidence
     */
    public function withEvidence(array $evidence): self
    {
        return new self($this->allowed, $this->errorCode, $this->failedPrecondition, [...$this->evidence, ...$evidence]);
    }
}

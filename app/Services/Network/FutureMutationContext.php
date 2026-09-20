<?php

namespace App\Services\Network;

final readonly class FutureMutationContext
{
    private function __construct(private CredentialReference $credential, private string $operation, private string $targetIdentityRef, private string $idempotencyRef, private string $fencingRef) {}

    public static function fromArray(array $data): self
    {
        $credential = CredentialReference::fromArray([
            'tenant_ref' => $data['tenant_ref'] ?? null,
            'router_ref' => $data['router_ref'] ?? null,
            'agent_ref' => $data['agent_ref'] ?? null,
            'credential_ref' => $data['credential_ref'] ?? null,
            'purpose' => $data['credential_purpose'] ?? null,
            'version' => $data['credential_version'] ?? null,
        ]);

        if ($credential->purpose !== CredentialPurpose::OPERATOR || ! is_string($data['operation'] ?? null) || $data['operation'] === '' || ! is_string($data['target_identity_ref'] ?? null) || $data['target_identity_ref'] === '' || ! is_string($data['idempotency_ref'] ?? null) || $data['idempotency_ref'] === '' || ! is_string($data['fencing_ref'] ?? null) || $data['fencing_ref'] === '') {
            throw CredentialReferenceException::invalid();
        }

        return new self($credential, $data['operation'], $data['target_identity_ref'], $data['idempotency_ref'], $data['fencing_ref']);
    }

    public function toArray(): array
    {
        return $this->credential->toArray() + [
            'credential_purpose' => $this->credential->purpose->value,
            'credential_version' => $this->credential->version,
            'operation' => $this->operation,
            'target_identity_ref' => $this->targetIdentityRef,
            'idempotency_ref' => $this->idempotencyRef,
            'fencing_ref' => $this->fencingRef,
        ];
    }
}
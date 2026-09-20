<?php

namespace App\Services\Network;

final readonly class CredentialReference
{
    public function __construct(
        public string $tenantRef,
        public string $routerRef,
        public string $agentRef,
        public string $credentialRef,
        public CredentialPurpose $purpose,
        public int $version,
    ) {}

    public static function fromArray(array $data): self
    {
        $tenantRef = self::ref($data['tenant_ref'] ?? null);
        $routerRef = self::ref($data['router_ref'] ?? null);
        $agentRef = self::ref($data['agent_ref'] ?? null);
        $credentialRef = self::ref($data['credential_ref'] ?? null);
        $purpose = is_string($data['purpose'] ?? null) ? CredentialPurpose::tryFrom($data['purpose']) : null;
        $version = $data['version'] ?? null;

        if ($purpose === null || ! is_int($version) || $version < 1 || strlen($credentialRef) > 191 || preg_match('/(?:password|secret|token|credential)/i', $credentialRef)) {
            throw CredentialReferenceException::invalid();
        }

        return new self($tenantRef, $routerRef, $agentRef, $credentialRef, $purpose, $version);
    }

    public function assertScope(string $tenantRef, string $routerRef, string $agentRef): void
    {
        if ($this->tenantRef !== $tenantRef || $this->routerRef !== $routerRef || $this->agentRef !== $agentRef) {
            throw CredentialReferenceException::invalid();
        }
    }

    public function toArray(): array
    {
        return [
            'tenant_ref' => $this->tenantRef,
            'router_ref' => $this->routerRef,
            'agent_ref' => $this->agentRef,
            'credential_ref' => $this->credentialRef,
            'purpose' => $this->purpose->value,
            'version' => $this->version,
        ];
    }

    private static function ref(mixed $value): string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 191 || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/-]*\z/', $value)) {
            throw CredentialReferenceException::invalid();
        }

        return $value;
    }
}
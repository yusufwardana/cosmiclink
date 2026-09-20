<?php

namespace App\Services\Network;

use App\Models\Router;

final readonly class CredentialReference
{
    public function __construct(
        public string $tenantRef,
        public string $routerRef,
        public string $agentRef,
        public string $installationId,
        public string $credentialRef,
        public CredentialPurpose $purpose,
        public int $version,
    ) {}

    public static function fromArray(array $data): self
    {
        $tenantRef = self::ref($data['tenant_ref'] ?? null);
        $routerRef = self::ref($data['router_ref'] ?? null);
        $agentRef = self::ref($data['agent_ref'] ?? null);
        $installationId = self::ref($data['installation_id'] ?? null);
        $credentialRef = self::ref($data['credential_ref'] ?? null);
        $purpose = is_string($data['purpose'] ?? null) ? CredentialPurpose::tryFrom($data['purpose']) : null;
        $version = $data['version'] ?? null;

        if ($purpose === null || ! is_int($version) || $version < 1 || strlen($credentialRef) > 191 || preg_match('/(?:password|secret|token|credential)/i', $credentialRef)) {
            throw CredentialReferenceException::invalid();
        }

        return new self($tenantRef, $routerRef, $agentRef, $installationId, $credentialRef, $purpose, $version);
    }

    public function assertScope(string $tenantRef, string $routerRef, string $agentRef, string $installationId): void
    {
        if ($this->tenantRef !== $tenantRef || $this->routerRef !== $routerRef || $this->agentRef !== $agentRef) {
            throw CredentialReferenceException::invalid();
        }
        if ($this->installationId !== $installationId) {
            throw CredentialReferenceException::invalid();
        }
    }

    public function toArray(): array
    {
        return [
            'tenant_ref' => $this->tenantRef,
            'router_ref' => $this->routerRef,
            'agent_ref' => $this->agentRef,
            'installation_id' => $this->installationId,
            'credential_ref' => $this->credentialRef,
            'purpose' => $this->purpose->value,
            'version' => $this->version,
        ];
    }

    public static function fromRouter(Router $router): self
    {
        return self::fromArray([
            'tenant_ref' => (string) $router->tenant_id,
            'router_ref' => (string) $router->id,
            'agent_ref' => $router->observer_agent_ref,
            'installation_id' => $router->observer_installation_id,
            'credential_ref' => $router->observer_credential_ref,
            'purpose' => $router->observer_credential_purpose,
            'version' => $router->observer_credential_version,
        ]);
    }

    private static function ref(mixed $value): string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 191 || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/-]*\z/', $value)) {
            throw CredentialReferenceException::invalid();
        }

        return $value;
    }
}

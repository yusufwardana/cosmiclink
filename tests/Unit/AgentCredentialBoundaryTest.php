<?php

namespace Tests\Unit;

use App\Services\Network\CredentialPurpose;
use App\Services\Network\CredentialReference;
use App\Services\Network\CredentialReferenceException;
use App\Services\Network\FutureMutationContext;
use PHPUnit\Framework\TestCase;

class AgentCredentialBoundaryTest extends TestCase
{
    public function test_observer_and_operator_purposes_are_explicit_and_unknown_or_empty_purposes_fail_closed(): void
    {
        $this->assertSame('OBSERVER', CredentialPurpose::OBSERVER->value);
        $this->assertSame('OPERATOR', CredentialPurpose::OPERATOR->value);
        $this->assertNull(CredentialPurpose::tryFrom(''));
        $this->assertNull(CredentialPurpose::tryFrom('UNKNOWN'));

        $this->expectException(CredentialReferenceException::class);
        CredentialReference::fromArray($this->reference('UNKNOWN'));
    }

    public function test_reference_requires_strict_non_secret_fields_and_rejects_malformed_values(): void
    {
        $reference = CredentialReference::fromArray($this->reference('OPERATOR'));

        $this->assertSame('tenant-1', $reference->tenantRef);
        $this->assertSame('agent-1', $reference->agentRef);
        $this->assertSame(CredentialPurpose::OPERATOR, $reference->purpose);
        $this->assertStringNotContainsString('password', json_encode($reference));

        foreach ([
            ['credential_ref' => ''],
            ['credential_ref' => 'DO_NOT_LEAK_OPERATOR_SECRET_123'],
            ['purpose' => ''],
            ['version' => 0],
        ] as $override) {
            try {
                CredentialReference::fromArray(array_replace($this->reference('OBSERVER'), $override));
                $this->fail('Malformed credential reference was accepted.');
            } catch (CredentialReferenceException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_reference_rejects_cross_scope_context(): void
    {
        $reference = CredentialReference::fromArray($this->reference('OPERATOR'));

        $this->expectException(CredentialReferenceException::class);
        $reference->assertScope('tenant-2', 'router-1', 'agent-1', 'installation-1');
    }

    public function test_future_mutation_context_has_only_reference_and_authorization_fields(): void
    {
        $context = FutureMutationContext::fromArray([
            'tenant_ref' => 'tenant-1',
            'agent_ref' => 'agent-1',
            'installation_id' => 'installation-1',
            'router_ref' => 'router-1',
            'credential_ref' => 'router-1/operator/v1',
            'credential_purpose' => 'OPERATOR',
            'credential_version' => 1,
            'operation' => 'ENABLE_PPPOE',
            'target_identity_ref' => '*7',
            'idempotency_ref' => 'idem-1',
            'fencing_ref' => 'fence-1',
        ]);

        $payload = $context->toArray();

        $this->assertSame('OPERATOR', $payload['credential_purpose']);
        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('username', $payload);
        $this->assertArrayNotHasKey('connection', $payload);
        $this->assertStringNotContainsString('DO_NOT_LEAK_OPERATOR_SECRET_', json_encode($payload));
    }

    private function reference(string $purpose): array
    {
        return [
            'tenant_ref' => 'tenant-1',
            'router_ref' => 'router-1',
            'agent_ref' => 'agent-1',
            'installation_id' => 'installation-1',
            'credential_ref' => 'router-1/'.strtolower($purpose).'/v1',
            'purpose' => $purpose,
            'version' => 1,
        ];
    }
}

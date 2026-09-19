<?php

namespace Tests\Unit;

use App\Support\Network\RouterResourceIdentity;
use PHPUnit\Framework\TestCase;

class RouterResourceIdentityTest extends TestCase
{
    public function test_routeros_id_reference_is_recognised_and_normalised(): void
    {
        $identity = RouterResourceIdentity::parse('*7');

        $this->assertInstanceOf(RouterResourceIdentity::class, $identity);
        $this->assertSame(RouterResourceIdentity::TYPE_ROUTEROS_ID, $identity->type());
        $this->assertSame('*7', $identity->ref());
        $this->assertTrue($identity->isRouterOsId());
        $this->assertFalse($identity->isNameFallback());
        $this->assertFalse($identity->isSimulated());
    }

    public function test_routeros_id_fingerprint_is_stable_across_equivalent_spellings(): void
    {
        $plain = RouterResourceIdentity::parse('*7');
        $prefixed = RouterResourceIdentity::parse(' =*7 ');
        $upperHex = RouterResourceIdentity::parse('*A');

        $this->assertNotNull($plain);
        $this->assertNotNull($prefixed);
        $this->assertNotNull($upperHex);
        $this->assertSame($plain->fingerprint(), $prefixed->fingerprint());
        $this->assertSame(64, strlen($plain->fingerprint()));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plain->fingerprint());
        $this->assertNotSame($plain->fingerprint(), $upperHex->fingerprint());
    }

    public function test_plain_reference_is_treated_as_name_fallback_only(): void
    {
        $identity = RouterResourceIdentity::parse('customer-01');

        $this->assertInstanceOf(RouterResourceIdentity::class, $identity);
        $this->assertSame(RouterResourceIdentity::TYPE_NAME, $identity->type());
        $this->assertTrue($identity->isNameFallback());
        $this->assertFalse($identity->isRouterOsId());
    }

    public function test_simulated_reference_is_labelled_as_a_simulation_artifact(): void
    {
        $identity = RouterResourceIdentity::parse('sim:*12');

        $this->assertInstanceOf(RouterResourceIdentity::class, $identity);
        $this->assertSame(RouterResourceIdentity::TYPE_SIMULATED, $identity->type());
        $this->assertTrue($identity->isSimulated());
        $this->assertFalse($identity->isRouterOsId());
    }

    public function test_unsafe_or_unsupported_references_are_rejected(): void
    {
        $rejected = [
            'empty' => '',
            'whitespace' => '   ',
            'embedded_control_character' => "bad\nid",
            'assignment_character' => 'name=admin',
            'embedded_space' => 'bad id',
            'slash_path' => '/ppp/secret/*7',
            'overlong' => str_repeat('x', 129),
            'non_hex_routeros_id' => '*zz',
            'too_long_routeros_id' => '*1234567',
        ];

        foreach ($rejected as $label => $reference) {
            $this->assertNull(RouterResourceIdentity::parse($reference), "rejected reference: {$label}");
        }
    }

    public function test_projection_persists_only_safe_identity_fields(): void
    {
        $identity = RouterResourceIdentity::parse('*7');

        $this->assertNotNull($identity);
        $this->assertSame(
            ['identity_type', 'identity_ref', 'identity_fingerprint'],
            array_keys($identity->projection())
        );
        $this->assertSame(RouterResourceIdentity::TYPE_ROUTEROS_ID, $identity->projection()['identity_type']);
        $this->assertSame($identity->fingerprint(), $identity->projection()['identity_fingerprint']);
    }

    public function test_matches_compares_canonical_routeros_references_only(): void
    {
        $identity = RouterResourceIdentity::parse('*7');

        $this->assertNotNull($identity);
        $this->assertTrue($identity->matches('=*7'));
        $this->assertFalse($identity->matches('*8'));
        $this->assertFalse($identity->matches(null));
        $this->assertFalse($identity->matches('customer-01'));
    }
}

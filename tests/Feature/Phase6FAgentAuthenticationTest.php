<?php

namespace Tests\Feature;

use App\Models\NetworkAgent;
use App\Models\Tenant;
use App\Services\Network\NetworkAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase6FAgentAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_tokens_use_indexed_lookup_and_never_store_plaintext(): void
    {
        $service = app(NetworkAgentService::class);
        [$agent, $token] = $service->enroll(Tenant::factory()->create(), 'Agent');
        $parts = explode('.', $token);
        $this->assertCount(2, $parts);
        [$id, $secret] = $parts;
        $this->assertSame($id, $agent->token_id);
        $this->assertTrue(Hash::check($secret, $agent->token_hash));
        DB::enableQueryLog();
        $this->assertSame($agent->id, $service->authenticate($token)?->id);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('"token_id" =', $queries[0]['query']);
        $this->assertNull($service->authenticate($id.'.wrong'));
        $this->assertNull($service->authenticate('malformed.token.extra'));
        $this->assertStringNotContainsString($secret, json_encode($agent->fresh()->getAttributes()));
        $this->assertArrayNotHasKey('token_hash', $agent->toArray());
    }

    public function test_legacy_credentials_remain_valid_without_rotation(): void
    {
        $token = Str::random(64);
        $agent = NetworkAgent::create(['tenant_id' => Tenant::factory()->create()->id, 'identifier' => (string) Str::uuid(), 'name' => 'Legacy', 'token_hash' => Hash::make($token)]);
        $hash = $agent->token_hash;
        $this->assertSame($agent->id, app(NetworkAgentService::class)->authenticate($token)?->id);
        $this->assertSame($hash, $agent->fresh()->token_hash);
        $this->assertNull($agent->fresh()->token_id);
    }
}

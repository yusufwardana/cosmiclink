<?php

namespace App\Services\Network;

use App\Models\NetworkAgent;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Explicit local disaster recovery, not enrollment or an HTTP endpoint. */
final class AcceptanceRecoveryService
{
    public function recover(#[\SensitiveParameter] array $manifest): array
    {
        $live = DB::connection()->getPdo()->query('select current_database()')->fetchColumn();
        if ($live !== 'cosmiclink_phase6i_acceptance' && ! (app()->runningUnitTests() && $live === 'cosmiclink_test')) {
            throw new InvalidArgumentException('Acceptance database required.');
        }
        if (config('network.mutations_enabled') || config('network.mutation_provider') !== 'fake' || config('network.driver') !== 'fake') {
            throw new InvalidArgumentException('Safe defaults required.');
        }
        // Never return validation input: it includes the existing bearer token.
        $valid = validator($manifest, [
            'tenant_ref' => ['required', 'integer', 'min:1'], 'router_ref' => ['required', 'integer', 'min:1'],
            'agent_ref' => ['required', 'uuid'], 'installation_id' => ['required', 'uuid'],
            'credential_ref' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1'],
            'token' => ['required', 'string', 'regex:/\A[a-f0-9]{32}\.[A-Za-z0-9]{64}\z/'],
        ]);
        if ($valid->fails() || count($manifest) !== 7) {
            throw new InvalidArgumentException('Invalid recovery manifest.');
        }

        return DB::transaction(function () use ($manifest) {
            // Serialize this one-time lifecycle; never overwrite existing scope.
            DB::select('select pg_advisory_xact_lock(6060921)');
            if (Tenant::whereKey($manifest['tenant_ref'])->exists() || Router::whereKey($manifest['router_ref'])->exists()
                || NetworkAgent::where('identifier', $manifest['agent_ref'])->exists()) {
                throw new InvalidArgumentException('Recovery scope is not vacant.');
            }
            $tenant = new Tenant(['name' => 'Phase 6I Hardware Acceptance', 'slug' => 'phase6i-acceptance']);
            $tenant->id = (int) $manifest['tenant_ref'];
            $tenant->save();
            $router = new Router([
                'tenant_id' => $tenant->id, 'name' => 'Phase 6I sandbox router', 'host' => '10.10.12.1',
                'api_port' => 8728, 'username' => 'local-observer-only', 'driver' => 'fake', 'status' => 'unavailable',
            ]);
            $router->id = (int) $manifest['router_ref'];
            $router->save();
            [$tokenId, $secret] = explode('.', $manifest['token'], 2);
            $agent = NetworkAgent::create([
                'tenant_id' => $tenant->id, 'identifier' => $manifest['agent_ref'], 'name' => 'Phase 6I preserved Agent',
                'token_id' => $tokenId, 'token_hash' => Hash::make($secret),
                'metadata' => ['recovery_installation_id' => $manifest['installation_id']],
            ]);
            $actor = new User([
                'tenant_id' => $tenant->id, 'name' => 'Phase 6I acceptance operator',
                'email' => 'phase6i-acceptance@localhost.invalid', 'password' => Str::random(64),
            ]);
            $actor->role = 'owner';
            $actor->save();
            // Explicit restored IDs must not collide with later generated IDs.
            // Unit tests roll back their rows and must not advance these sequences.
            if (! app()->runningUnitTests()) {
                foreach (['tenants', 'routers'] as $table) {
                    DB::select("select setval(pg_get_serial_sequence('{$table}', 'id'), greatest((select max(id) from {$table}), (select last_value from {$table}_id_seq)), true)");
                }
            }
            DB::table('network_agent_events')->insert([
                'tenant_id' => $tenant->id, 'network_agent_id' => $agent->id,
                'type' => 'AGENT_ACCEPTANCE_RECOVERED', 'code' => 'OBSERVER_REACTIVATION_REQUIRED', 'created_at' => now(),
            ]);

            return ['tenant_id' => $tenant->id, 'router_id' => $router->id, 'agent_id' => $agent->id,
                'agent_identifier' => $agent->identifier, 'actor_id' => $actor->id];
        });
    }
}

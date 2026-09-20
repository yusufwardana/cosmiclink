<?php

namespace App\Services\Network;

use App\Models\NetworkAgent;
use App\Models\Router;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ObserverReferenceService
{
    public const LEGACY = 'LEGACY';

    public const LOCAL_OBSERVER_READY = 'LOCAL_OBSERVER_READY';

    public const REFERENCE_SYNCED = 'REFERENCE_SYNCED';

    public const LOCAL_OBSERVER_ACTIVE = 'LOCAL_OBSERVER_ACTIVE';

    public const LEGACY_RETIRABLE = 'LEGACY_RETIRABLE';

    public function sync(NetworkAgent $authenticatedAgent, array $payload): Router
    {
        $data = $this->validate($payload);
        abort_unless((string) $authenticatedAgent->tenant_id === $data['tenant_ref'], 403);
        abort_unless((string) $authenticatedAgent->identifier === $data['agent_ref'], 403);

        return DB::transaction(function () use ($authenticatedAgent, $data) {
            $router = Router::query()->where('id', $data['router_ref'])->where('tenant_id', $authenticatedAgent->tenant_id)->lockForUpdate()->firstOrFail();
            $router->forceFill([
                'observer_synced_agent_ref' => $data['agent_ref'],
                'observer_synced_installation_id' => $data['installation_id'],
                'observer_synced_credential_ref' => $data['credential_ref'],
                'observer_synced_credential_purpose' => $data['purpose'],
                'observer_synced_credential_version' => $data['version'],
                'observer_synced_credential_status' => $data['status'],
                'observer_migration_state' => $router->observer_migration_state === self::LOCAL_OBSERVER_ACTIVE ? self::LOCAL_OBSERVER_ACTIVE : self::REFERENCE_SYNCED,
                'observer_reference_synced_at' => now(),
            ])->save();
            DB::table('network_agent_events')->insert([
                'tenant_id' => $authenticatedAgent->tenant_id,
                'network_agent_id' => $authenticatedAgent->id,
                'type' => 'OBSERVER_REFERENCE_SYNCED',
                'code' => $data['status'],
                'created_at' => now(),
            ]);

            return $router->fresh();
        });
    }

    public function bind(Router $router, NetworkAgent $agent, array $reference): Router
    {
        $data = $this->validate($reference);
        abort_unless((string) $router->tenant_id === $data['tenant_ref'] && (string) $agent->tenant_id === $data['tenant_ref'], 403);
        abort_unless((string) $agent->identifier === $data['agent_ref'], 403);
        abort_unless((string) $router->id === $data['router_ref'], 403);

        return DB::transaction(function () use ($router, $data) {
            $router = Router::query()->lockForUpdate()->findOrFail($router->id);
            abort_unless($router->observer_synced_credential_ref === $data['credential_ref'] && $router->observer_synced_credential_version === $data['version'], 422);
            $router->forceFill([
                'observer_agent_ref' => $data['agent_ref'],
                'observer_installation_id' => $data['installation_id'],
                'observer_credential_ref' => $data['credential_ref'],
                'observer_credential_purpose' => $data['purpose'],
                'observer_credential_version' => $data['version'],
                'observer_credential_status' => $data['status'],
                'observer_migration_state' => self::LOCAL_OBSERVER_READY,
                'observer_reference_bound_at' => now(),
            ])->save();

            return $router->fresh();
        });
    }

    public function activate(Router $router, NetworkAgent $agent): Router
    {
        abort_unless($router->tenant_id === $agent->tenant_id && $router->observer_agent_ref === $agent->identifier, 403);
        abort_unless($router->observer_credential_purpose === CredentialPurpose::OBSERVER->value, 422);
        abort_unless($router->observer_credential_version > 0 && $router->observer_credential_status === 'ACTIVE', 422);
        abort_unless($router->observer_reference_synced_at !== null, 422);
        abort_unless($router->observer_synced_credential_ref === $router->observer_credential_ref && $router->observer_synced_credential_version === $router->observer_credential_version && $router->observer_synced_credential_status === 'ACTIVE', 422);

        $router->update(['observer_migration_state' => self::LOCAL_OBSERVER_ACTIVE]);
        DB::table('network_agent_events')->insert(['tenant_id' => $router->tenant_id, 'network_agent_id' => $agent->id, 'type' => 'OBSERVER_LOCAL_CUTOVER', 'code' => 'LOCAL_OBSERVER_ACTIVE', 'created_at' => now()]);

        return $router->fresh();
    }

    public function switchReference(Router $router, NetworkAgent $agent, array $reference): Router
    {
        $data = $this->validate($reference);
        abort_unless((string) $router->tenant_id === $data['tenant_ref'] && (string) $agent->tenant_id === $data['tenant_ref'], 403);
        abort_unless((string) $router->id === $data['router_ref'] && (string) $agent->identifier === $data['agent_ref'], 403);
        abort_unless($data['purpose'] === CredentialPurpose::OBSERVER->value && $data['status'] === 'ACTIVE', 422);
        abort_unless($router->observer_synced_credential_ref === $data['credential_ref'] && $router->observer_synced_credential_version === $data['version'], 422);

        $router->update([
            'observer_agent_ref' => $data['agent_ref'],
            'observer_installation_id' => $data['installation_id'],
            'observer_credential_ref' => $data['credential_ref'],
            'observer_credential_purpose' => $data['purpose'],
            'observer_credential_version' => $data['version'],
            'observer_credential_status' => $data['status'],
            'observer_migration_state' => self::LOCAL_OBSERVER_ACTIVE,
            'observer_reference_bound_at' => now(),
        ]);
        DB::table('network_agent_events')->insert(['tenant_id' => $router->tenant_id, 'network_agent_id' => $agent->id, 'type' => 'OBSERVER_REFERENCE_SWITCHED', 'code' => (string) $data['version'], 'created_at' => now()]);

        return $router->fresh();
    }

    private function validate(array $payload): array
    {
        $unexpected = array_diff(array_keys($payload), ['tenant_ref', 'router_ref', 'agent_ref', 'installation_id', 'credential_ref', 'purpose', 'version', 'status']);
        if ($unexpected !== []) {
            throw new InvalidArgumentException('Observer reference metadata contains unsupported fields.');
        }
        foreach (['tenant_ref', 'router_ref', 'agent_ref', 'installation_id', 'credential_ref', 'purpose', 'status'] as $field) {
            if (! is_string($payload[$field] ?? null) || $payload[$field] === '' || strlen($payload[$field]) > 191) {
                throw new InvalidArgumentException('Observer reference metadata is invalid.');
            }
        }
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/-]*\z/', $payload['credential_ref']) || preg_match('/(?:password|secret|token|credential)/i', $payload['credential_ref'])) {
            throw new InvalidArgumentException('Observer credential reference is invalid.');
        }
        if ($payload['purpose'] !== CredentialPurpose::OBSERVER->value || ! is_int($payload['version']) || $payload['version'] < 1 || ! in_array($payload['status'], ['ACTIVE', 'REVOKED', 'RETIRED'], true)) {
            throw new InvalidArgumentException('Observer reference lifecycle metadata is invalid.');
        }

        return $payload;
    }
}

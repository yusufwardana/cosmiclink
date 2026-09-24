<?php

namespace App\Services\Network;

use App\Models\NetworkAgent;
use App\Models\Router;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class ObserverBindingRecoveryService
{
    public function recover(array $input): array
    {
        $this->assertRuntimeSafety();
        $data = $this->validateInput($input);
        $tenant = Tenant::query()->whereKey($data['tenant_id'])->firstOrFail();
        $helper = $this->helperPath($data['helper']);
        $vault = $this->vault($helper, 'inspect', $data);

        if ($vault['purpose'] !== CredentialPurpose::OBSERVER->value || $vault['status'] !== 'ACTIVE') {
            throw new InvalidArgumentException('Preserved credential is not an active OBSERVER credential.');
        }
        if ($vault['installation_id'] !== $data['installation_id']) {
            throw new InvalidArgumentException('Preserved installation identity mismatch.');
        }
        $data['agent_ref'] = $vault['agent_ref'];

        $existing = Router::query()
            ->where('tenant_id', $tenant->id)
            ->where('host', $data['host'])
            ->where('api_port', $data['port'])
            ->first();
        $agent = NetworkAgent::query()->where('identifier', $vault['agent_ref'])->first();

        if ($existing || $agent) {
            $alreadyRecovered = $existing && $agent
                && $vault['tenant_ref'] === (string) $tenant->id
                && $vault['router_ref'] === (string) $existing->id
                && $existing->observer_agent_ref === $vault['agent_ref']
                && $existing->observer_credential_ref === $data['credential_ref']
                && $existing->observer_credential_version === $data['version']
                && $existing->observer_migration_state === ObserverReferenceService::LOCAL_OBSERVER_ACTIVE
                && (int) $existing->tenant_id === (int) $tenant->id
                && (int) $agent->tenant_id === (int) $tenant->id;
            if (! $alreadyRecovered) {
                throw new InvalidArgumentException('Recovery scope is already occupied or ambiguous.');
            }

            $this->vault($helper, 'validate', $data + ['new_router_ref' => (string) $existing->id, 'agent_ref' => $vault['agent_ref']]);

            return ['status' => 'ALREADY_RECOVERED', 'tenant_id' => $tenant->id, 'router_id' => $existing->id, 'agent_id' => $agent->id, 'agent_ref' => $agent->identifier];
        }

        $oldScope = $data;
        $newScope = $data;
        $rebound = false;

        try {
            return DB::transaction(function () use ($tenant, $data, $helper, $oldScope, &$newScope, &$rebound): array {
                DB::select('select pg_advisory_xact_lock(6060922)');

                if (Router::query()->where('tenant_id', $tenant->id)->where('host', $data['host'])->where('api_port', $data['port'])->exists() || NetworkAgent::query()->where('identifier', $data['agent_ref'])->exists()) {
                    throw new InvalidArgumentException('Recovery scope became occupied during preflight.');
                }

                $router = new Router([
                    'tenant_id' => $tenant->id,
                    'name' => 'MikroTik '.$data['host'],
                    'description' => 'Recovered read-only OBSERVER binding',
                    'host' => $data['host'],
                    'api_port' => $data['port'],
                    'username' => 'observer-reference-only',
                    'status' => 'available',
                    'driver' => config('network.driver', 'fake'),
                    'observer_agent_ref' => $data['agent_ref'],
                    'observer_installation_id' => $data['installation_id'],
                    'observer_credential_ref' => $data['credential_ref'],
                    'observer_credential_purpose' => CredentialPurpose::OBSERVER->value,
                    'observer_credential_version' => $data['version'],
                    'observer_credential_status' => 'ACTIVE',
                    'observer_migration_state' => ObserverReferenceService::LOCAL_OBSERVER_ACTIVE,
                    'observer_reference_bound_at' => now(),
                    'observer_reference_synced_at' => now(),
                    'observer_synced_agent_ref' => $data['agent_ref'],
                    'observer_synced_installation_id' => $data['installation_id'],
                    'observer_synced_credential_ref' => $data['credential_ref'],
                    'observer_synced_credential_purpose' => CredentialPurpose::OBSERVER->value,
                    'observer_synced_credential_version' => $data['version'],
                    'observer_synced_credential_status' => 'ACTIVE',
                ]);
                $router->save();

                $agent = NetworkAgent::create([
                    'tenant_id' => $tenant->id,
                    'identifier' => $data['agent_ref'],
                    'name' => 'Recovered local Agent',
                    'token_id' => bin2hex(random_bytes(16)),
                    'token_hash' => Hash::make(Str::random(64)),
                    'metadata' => ['recovery_installation_id' => $data['installation_id'], 'observer_only' => true],
                ]);

                $newScope['new_router_ref'] = (string) $router->id;
                $newScope['agent_ref'] = $agent->identifier;
                $this->vault($helper, 'rebind', $newScope);
                $rebound = true;

                $this->vault($helper, 'validate', $newScope);

                DB::table('network_agent_events')->insert([
                    'tenant_id' => $tenant->id,
                    'network_agent_id' => $agent->id,
                    'type' => 'OBSERVER_BINDING_RECOVERED',
                    'code' => 'LOCAL_OBSERVER_ACTIVE',
                    'created_at' => now(),
                ]);

                return ['status' => 'RECOVERED', 'tenant_id' => $tenant->id, 'router_id' => $router->id, 'agent_id' => $agent->id, 'agent_ref' => $agent->identifier];
            });
        } catch (\Throwable $exception) {
            if ($rebound) {
                try {
                    $rollbackScope = $oldScope;
                    $rollbackScope['old_tenant_ref'] = (string) $data['tenant_id'];
                    $rollbackScope['old_router_ref'] = $newScope['new_router_ref'] ?? '';
                    $rollbackScope['new_tenant_ref'] = $oldScope['old_tenant_ref'];
                    $rollbackScope['new_router_ref'] = $oldScope['old_router_ref'];
                    $rollbackScope['agent_ref'] = $data['agent_ref'];
                    $this->vault($helper, 'rebind', $rollbackScope);
                } catch (\Throwable $rollbackException) {
                    throw new RuntimeException('Recovery failed and vault rollback failed; manual recovery is required.', 0, $rollbackException);
                }
            }

            throw $exception;
        }
    }

    private function validateInput(array $input): array
    {
        $data = validator($input, [
            'tenant_id' => ['required', 'integer', 'min:1'],
            'host' => ['required', 'ip'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'installation_id' => ['required', 'uuid'],
            'credential_ref' => ['required', 'uuid'],
            'version' => ['required', 'integer', 'min:1'],
            'old_tenant_ref' => ['required', 'string', 'max:191'],
            'old_router_ref' => ['required', 'string', 'max:191'],
            'helper' => ['nullable', 'string', 'max:500'],
        ])->validate();

        return $data;
    }

    private function assertRuntimeSafety(): void
    {
        $database = DB::connection()->getPdo()->query('select current_database()')->fetchColumn();
        if (app()->environment() !== 'local' || $database !== 'cosmiclink') {
            throw new InvalidArgumentException('Observer recovery requires APP_ENV=local and database cosmiclink.');
        }
        if ((bool) config('network.mutations_enabled') || config('network.mutation_provider') !== 'fake') {
            throw new InvalidArgumentException('Network mutation settings are not safe for recovery.');
        }
    }

    private function helperPath(?string $helper): string
    {
        return $helper ?: 'go';
    }

    private function vault(string $helper, string $operation, array $data): array
    {
        $newRouter = (string) ($data['new_router_ref'] ?? $data['new_router'] ?? '');
        $newTenant = (string) ($data['new_tenant_ref'] ?? $data['tenant_id']);
        $arguments = [$operation, '--data-dir', env('COSMICLINK_AGENT_DATA_DIR'), '--old-tenant', $data['old_tenant_ref'], '--old-router', $data['old_router_ref'], '--new-tenant', $newTenant, '--new-router', $newRouter, '--agent', (string) ($data['agent_ref'] ?? ''), '--installation', $data['installation_id'], '--credential', $data['credential_ref'], '--version', (string) $data['version']];

        if ($operation !== 'inspect' && $newRouter === '') {
            throw new InvalidArgumentException('New router reference is required for vault operation.');
        }

        if ($helper === 'go') {
            $command = ['go', 'run', '.\\cmd\\observer-recovery', ...$arguments];
        } else {
            $command = [$helper, ...$arguments];
        }
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path('network-engine'));
        if (! is_resource($process)) {
            throw new RuntimeException('Vault recovery helper unavailable.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        unset($stderr);
        if ($exitCode !== 0) {
            throw new RuntimeException('Vault recovery operation failed.');
        }

        $decoded = json_decode($stdout, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Vault recovery helper returned invalid metadata.');
        }

        return $decoded;
    }
}
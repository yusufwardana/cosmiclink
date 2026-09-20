<?php

namespace App\Services\Network;

use App\Models\NetworkAgent;
use App\Models\NetworkAgentJob;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class NetworkAgentService
{
    private const SENSITIVE_KEYS = ['password', 'pass', 'secret', 'token', 'authorization', 'credential', 'credentials'];

    public function enroll(Tenant $tenant, string $name): array
    {
        return DB::transaction(function () use ($tenant, $name) {
            $secret = Str::random(64);
            $id = bin2hex(random_bytes(16));
            $agent = NetworkAgent::create(['tenant_id' => $tenant->id, 'identifier' => (string) Str::uuid(), 'name' => $name, 'token_id' => $id, 'token_hash' => Hash::make($secret)]);
            $this->event($agent, 'AGENT_ENROLLED');

            return [$agent, $id.'.'.$secret];
        });
    }

    public function authenticate(?string $token): ?NetworkAgent
    {
        if (! is_string($token) || $token === '' || strlen($token) > 256) {
            return null;
        }
        if (str_contains($token, '.')) {
            $parts = explode('.', $token);
            if (count($parts) !== 2 || ! preg_match('/^[a-f0-9]{32}$/D', $parts[0]) || $parts[1] === '') {
                return null;
            }
            $agent = NetworkAgent::where('token_id', $parts[0])->first();

            return $agent && Hash::check($parts[1], $agent->token_hash) ? $agent : null;
        }
        // Explicit migration bridge: only pre-indexed credentials use legacy lookup.
        foreach (NetworkAgent::whereNull('token_id')->cursor() as $agent) {
            if (Hash::check($token, $agent->token_hash)) {
                return $agent;
            }
        }

        return null;
    }

    public function heartbeat(NetworkAgent $agent, array $data): NetworkAgent
    {
        $data = validator($data, [
            'version' => ['sometimes', 'string', 'max:100'],
            'capabilities' => ['sometimes', 'array', 'max:16'],
            'capabilities.*' => ['string', 'max:64'],
            'go_runtime' => ['sometimes', 'string', 'max:64', 'regex:/^go[0-9A-Za-z.]+$/'],
            'os' => ['sometimes', 'in:windows,linux,darwin,freebsd,openbsd,netbsd,dragonfly,solaris,illumos,aix,android,ios'],
            'architecture' => ['sometimes', 'in:amd64,arm64,386,arm,ppc64,ppc64le,riscv64,s390x,mips,mipsle,mips64,mips64le,loong64'],
            'uptime_seconds' => ['sometimes', 'integer', 'min:0', 'max:315360000'],
        ])->validate();

        return DB::transaction(function () use ($agent, $data) {
            $agent = NetworkAgent::lockForUpdate()->findOrFail($agent->id);
            $before = app(NetworkAgentHealthService::class)->health($agent);
            $this->observe($agent);
            if ($agent->last_seen_at === null || $before !== 'ONLINE') {
                $this->event($agent, $agent->last_seen_at === null ? 'AGENT_ONLINE' : 'AGENT_RECOVERED');
            }
            $agent->update([
                'last_seen_at' => now(), 'observed_health' => 'ONLINE',
                'version' => $data['version'] ?? $agent->version,
                'capabilities' => array_values(array_unique(array_intersect($data['capabilities'] ?? $agent->capabilities ?? [], config('network_agents.capabilities')))),
                'metadata' => array_replace($agent->metadata ?? [], array_intersect_key($data, array_flip(['go_runtime', 'os', 'architecture', 'uptime_seconds']))),
            ]);

            return $agent;
        });
    }

    public function createDiscoveryJob(Router $router, NetworkAgent $agent, ?User $user): NetworkAgentJob
    {
        return $this->createJob($router, $agent, $user, NetworkAgentJob::DISCOVER_ROUTER);
    }

    public function createJob(Router $router, NetworkAgent $agent, ?User $user, string $type): NetworkAgentJob
    {
        if ($type !== NetworkAgentJob::DISCOVER_ROUTER) {
            throw new \InvalidArgumentException('Unsupported network agent job type.');
        }
        if ($router->tenant_id !== $agent->tenant_id || ($user && $user->tenant_id !== $router->tenant_id)) {
            throw new \InvalidArgumentException('Network agent job tenant ownership is invalid.');
        }

        if (! app(NetworkAgentCompatibilityService::class)->canDiscover($agent->fresh())) {
            throw new \InvalidArgumentException('Agent is not compatible with read-only discovery.');
        }

        return DB::transaction(function () use ($router, $agent, $user, $type) {
            $job = NetworkAgentJob::create(['tenant_id' => $router->tenant_id, 'network_agent_id' => $agent->id, 'router_id' => $router->id, 'initiated_by_user_id' => $user?->id, 'job_type' => $type, 'status' => NetworkAgentJob::PENDING]);
            $this->event($agent, 'JOB_CREATED', $job);

            return $job;
        });
    }

    public function claim(NetworkAgent $agent): ?array
    {
        return DB::transaction(function () use ($agent) {
            $agent = NetworkAgent::lockForUpdate()->findOrFail($agent->id);
            if (! $agent->isOnline() || ! app(NetworkAgentCompatibilityService::class)->canDiscover($agent)) {
                return null;
            }
            $job = NetworkAgentJob::query()->where('tenant_id', $agent->tenant_id)->where('network_agent_id', $agent->id)->where('status', NetworkAgentJob::PENDING)->orderBy('id')->lockForUpdate()->first();
            if (! $job || $job->job_type !== NetworkAgentJob::DISCOVER_ROUTER || $job->attempt >= config('network_agents.max_attempts') || ! app(NetworkAgentCompatibilityService::class)->canDiscover($agent, $job->attempt > 0)) {
                return null;
            }
            $job->update(['status' => NetworkAgentJob::RUNNING, 'attempt' => $job->attempt + 1, 'claimed_at' => now(), 'last_progress_at' => now(), 'lease_expires_at' => now()->addSeconds(config('network_agents.lease_seconds')), 'fence' => (string) Str::uuid(), 'lease_aware' => app(NetworkAgentCompatibilityService::class)->canDiscover($agent, true), 'completed_at' => null, 'error_code' => null, 'error_message' => null]);
            DB::table('network_agent_job_attempts')->insert(['tenant_id' => $job->tenant_id, 'network_agent_id' => $agent->id, 'network_agent_job_id' => $job->id, 'attempt' => $job->attempt, 'status' => 'RUNNING', 'claimed_at' => now(), 'last_progress_at' => now(), 'lease_expires_at' => $job->lease_expires_at]);
            $this->event($agent, 'JOB_CLAIMED', $job);
            $router = Router::query()->where('id', $job->router_id)->where('tenant_id', $agent->tenant_id)->firstOrFail();

            $jobPayload = ['id' => $job->id, 'type' => $job->job_type, 'attempt' => $job->attempt, 'fence' => $job->fence, 'renewal_seconds' => config('network_agents.renewal_seconds'), 'lease_expires_at' => $job->lease_expires_at->toISOString(), 'tenant_ref' => (string) $router->tenant_id, 'router_ref' => (string) $router->id, 'agent_ref' => (string) $agent->identifier];
            if ($router->observer_migration_state === 'LOCAL_OBSERVER_ACTIVE') {
                $reference = CredentialReference::fromRouter($router);
                if ($reference->purpose !== CredentialPurpose::OBSERVER) {
                    abort(422);
                }
                $jobPayload += ['credential_ref' => $reference->credentialRef, 'credential_purpose' => $reference->purpose->value, 'credential_version' => $reference->version, 'installation_id' => $reference->installationId];
            } else {
                $jobPayload['connection'] = ['host' => $router->host, 'port' => (int) $router->api_port, 'username' => $router->username, 'password' => $router->password(), 'transport' => config('network.routeros.transport'), 'connect_timeout_seconds' => (int) config('network.routeros.connect_timeout_seconds'), 'read_timeout_seconds' => (int) config('network.routeros.read_timeout_seconds'), 'insecure_tls' => (bool) config('network.routeros.insecure_tls')];
            }

            return ['job' => $jobPayload];
        });
    }

    public function submit(NetworkAgent $agent, NetworkAgentJob $job, array $data, NetworkDiscoveryService $discovery): NetworkAgentJob
    {
        if ($job->tenant_id !== $agent->tenant_id || $job->network_agent_id !== $agent->id || $job->job_type !== NetworkAgentJob::DISCOVER_ROUTER) {
            abort(403);
        }

        return DB::transaction(function () use ($agent, $job, $data, $discovery) {
            $job = NetworkAgentJob::lockForUpdate()->findOrFail($job->id);
            abort_unless($job->tenant_id === $agent->tenant_id && $job->network_agent_id === $agent->id && $job->job_type === NetworkAgentJob::DISCOVER_ROUTER, 403);
            $this->assertFence($job, $data);
            if (in_array($job->status, [NetworkAgentJob::SUCCEEDED, NetworkAgentJob::FAILED], true)) {
                return $job;
            }
            abort_unless($job->lease_expires_at && $job->lease_expires_at->isFuture(), 409);
            if ($job->status !== NetworkAgentJob::RUNNING || ! isset($data['success']) || ! is_bool($data['success'])) {
                abort(422);
            }
            $safe = $this->sanitize($data);
            $codes = ['ROUTER_UNAVAILABLE', 'ROUTER_UNREACHABLE', 'ROUTER_AUTH_FAILED', 'ROUTER_TIMEOUT', 'ROUTER_PROTOCOL_ERROR', 'DISCOVERY_FAILED', 'RESULT_REJECTED'];
            $safe['code'] = $data['success'] ? 'DISCOVERY_COMPLETE' : (in_array($data['code'] ?? '', $codes, true) ? $data['code'] : 'DISCOVERY_FAILED');
            $safe['message'] = $data['success'] ? 'Read-only discovery complete.' : 'Read-only discovery failed.';
            $safe['provider'] = in_array($data['provider'] ?? '', ['fake', 'routeros'], true) ? $data['provider'] : 'unknown';
            if ($data['success']) {
                $snapshot = $safe['snapshot'] ?? null;
                if (! is_array($snapshot)) {
                    abort(422);
                }
                validator($snapshot, [
                    'device' => ['required', 'array'],
                    'profiles' => ['present', 'array', 'max:10000'],
                    'accounts' => ['present', 'array', 'max:10000'],
                    'address_pools' => ['present', 'array', 'max:10000'],
                    'queues' => ['present', 'array', 'max:10000'],
                    'profiles.*' => ['array'], 'accounts.*' => ['array'],
                    'address_pools.*' => ['array'], 'queues.*' => ['array'],
                ])->validate();
                $router = Router::query()->where('id', $job->router_id)->where('tenant_id', $job->tenant_id)->firstOrFail();
                $discovery->persist($router, $job->initiatedBy, new DiscoveryResult(true, (string) ($safe['message'] ?? 'Read-only discovery complete'), null, ['provider' => $safe['provider'] ?? 'unknown', 'discovered_at' => $safe['discovered_at'] ?? now()->toISOString(), 'snapshot' => $snapshot]));
                $job->update(['status' => NetworkAgentJob::SUCCEEDED, 'completed_at' => now(), 'result_meta' => $this->meta($safe), 'error_code' => null, 'error_message' => null]);
            } else {
                $job->update(['status' => NetworkAgentJob::FAILED, 'completed_at' => now(), 'result_meta' => $this->meta($safe), 'error_code' => $safe['code'] ?? 'DISCOVERY_FAILED', 'error_message' => $safe['message'] ?? 'Network agent discovery failed.']);
            }

            $this->attemptQuery($job)->update(['status' => $job->status, 'completed_at' => now(), 'failure_code' => $job->error_code]);
            $this->event($job->agent, 'JOB_'.$job->status, $job, $job->error_code);

            return $job;
        });
    }

    private function assertFence(NetworkAgentJob $job, array $data): void
    {
        abort_unless(is_string($job->fence) && $job->fence !== '', 409);
        // Legacy omission is restricted to the original, non-lease-aware attempt.
        if (! $job->lease_aware && $job->attempt === 1 && ! isset($data['attempt']) && ! isset($data['fence'])) {
            return;
        }
        abort_unless(isset($data['attempt'], $data['fence']) && (int) $data['attempt'] === $job->attempt && is_string($data['fence']) && is_string($job->fence) && hash_equals($job->fence, $data['fence']), 409);
    }

    public function renew(NetworkAgent $agent, NetworkAgentJob $job, array $data): NetworkAgentJob
    {
        return DB::transaction(function () use ($agent, $job, $data) {
            $job = NetworkAgentJob::lockForUpdate()->findOrFail($job->id);
            abort_unless($job->tenant_id === $agent->tenant_id && $job->network_agent_id === $agent->id, 403);
            abort_unless($job->status === NetworkAgentJob::RUNNING && $job->lease_aware && $job->lease_expires_at?->isFuture(), 409);
            $this->assertFence($job, $data);
            $job->update(['last_progress_at' => now(), 'lease_expires_at' => now()->addSeconds(config('network_agents.lease_seconds'))]);
            $this->attemptQuery($job)->update(['last_progress_at' => now(), 'lease_expires_at' => $job->lease_expires_at]);

            return $job;
        });
    }

    public function recover(): int
    {
        foreach (NetworkAgent::select('id')->cursor() as $row) {
            DB::transaction(function () use ($row) {
                $this->observe(NetworkAgent::lockForUpdate()->findOrFail($row->id));
            });
        }
        $count = 0;
        $ids = NetworkAgentJob::where('status', NetworkAgentJob::RUNNING)->where(function ($query) {
            $query->where('lease_expires_at', '<=', now())->orWhereNull('lease_expires_at');
        })->pluck('id');
        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id) {
                $job = NetworkAgentJob::lockForUpdate()->findOrFail($id);
                if ($job->status !== NetworkAgentJob::RUNNING || $job->lease_expires_at?->isFuture()) {
                    return 0;
                }
                $code = $job->attempt >= config('network_agents.max_attempts') ? 'MAX_ATTEMPTS_EXCEEDED' : (! app(NetworkAgentCompatibilityService::class)->canDiscover($job->agent, true) ? 'INCOMPATIBLE_AGENT_RETRY' : 'AGENT_LEASE_EXPIRED');
                $retry = $code === 'AGENT_LEASE_EXPIRED';
                $this->attemptQuery($job)->update(['status' => 'EXPIRED', 'completed_at' => now(), 'failure_code' => $code]);
                $job->update(['status' => $retry ? NetworkAgentJob::PENDING : NetworkAgentJob::FAILED, 'fence' => null, 'lease_expires_at' => null, 'completed_at' => $retry ? null : now(), 'error_code' => $code, 'error_message' => 'Agent lease expired.']);
                $this->event($job->agent, $retry ? 'JOB_REQUEUED' : 'JOB_FAILED', $job, $code);

                return 1;
            });
        }

        return $count;
    }

    private function attemptQuery(NetworkAgentJob $job)
    {
        return DB::table('network_agent_job_attempts')->where('tenant_id', $job->tenant_id)->where('network_agent_job_id', $job->id)->where('attempt', $job->attempt);
    }

    private function observe(NetworkAgent $agent): void
    {
        $health = app(NetworkAgentHealthService::class)->health($agent);
        if ($agent->last_seen_at !== null && $agent->observed_health !== $health) {
            $this->event($agent, 'AGENT_'.$health);
            $agent->update(['observed_health' => $health]);
        }
    }

    private function event(NetworkAgent $agent, string $type, ?NetworkAgentJob $job = null, ?string $code = null): void
    {
        DB::table('network_agent_events')->insert(['tenant_id' => $agent->tenant_id, 'network_agent_id' => $agent->id, 'network_agent_job_id' => $job?->id, 'attempt' => $job?->attempt, 'type' => $type, 'code' => $code, 'created_at' => now()]);
    }

    private function meta(array $data): array
    {
        return array_intersect_key($data, array_flip(['provider', 'discovered_at', 'code', 'message']));
    }

    private function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            }
        }

        return $data;
    }
}

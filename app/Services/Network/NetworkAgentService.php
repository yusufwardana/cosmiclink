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
        $token = Str::random(64);
        $agent = NetworkAgent::create(['tenant_id' => $tenant->id, 'identifier' => (string) Str::uuid(), 'name' => $name, 'token_hash' => Hash::make($token)]);

        return [$agent, $token];
    }

    public function authenticate(?string $token): ?NetworkAgent
    {
        if (! is_string($token) || $token === '') {
            return null;
        }
        foreach (NetworkAgent::cursor() as $agent) {
            if (Hash::check($token, $agent->token_hash)) {
                return $agent;
            }
        }

        return null;
    }

    public function heartbeat(NetworkAgent $agent, array $data): NetworkAgent
    {
        $agent->update(['last_seen_at' => now(), 'version' => $data['version'] ?? $agent->version, 'capabilities' => $this->sanitize($data['capabilities'] ?? $agent->capabilities ?? [])]);

        return $agent->fresh();
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

        return NetworkAgentJob::create(['tenant_id' => $router->tenant_id, 'network_agent_id' => $agent->id, 'router_id' => $router->id, 'initiated_by_user_id' => $user?->id, 'job_type' => $type, 'status' => NetworkAgentJob::PENDING]);
    }

    public function claim(NetworkAgent $agent): ?array
    {
        return DB::transaction(function () use ($agent) {
            $job = NetworkAgentJob::query()->where('tenant_id', $agent->tenant_id)->where('network_agent_id', $agent->id)->where('status', NetworkAgentJob::PENDING)->orderBy('id')->lockForUpdate()->first();
            if (! $job) {
                return null;
            }
            $job->update(['status' => NetworkAgentJob::RUNNING, 'attempt' => $job->attempt + 1, 'claimed_at' => now()]);
            $router = Router::query()->where('id', $job->router_id)->where('tenant_id', $agent->tenant_id)->firstOrFail();

            return ['job' => ['id' => $job->id, 'type' => $job->job_type, 'router_ref' => (string) $router->id, 'connection' => ['host' => $router->host, 'port' => (int) $router->api_port, 'username' => $router->username, 'password' => $router->password(), 'transport' => config('network.routeros.transport'), 'connect_timeout_seconds' => (int) config('network.routeros.connect_timeout_seconds'), 'read_timeout_seconds' => (int) config('network.routeros.read_timeout_seconds'), 'insecure_tls' => (bool) config('network.routeros.insecure_tls')]]];
        });
    }

    public function submit(NetworkAgent $agent, NetworkAgentJob $job, array $data, NetworkDiscoveryService $discovery): NetworkAgentJob
    {
        if ($job->tenant_id !== $agent->tenant_id || $job->network_agent_id !== $agent->id || $job->job_type !== NetworkAgentJob::DISCOVER_ROUTER) {
            abort(403);
        }

        return DB::transaction(function () use ($job, $data, $discovery) {
            $job = NetworkAgentJob::lockForUpdate()->findOrFail($job->id);
            if (in_array($job->status, [NetworkAgentJob::SUCCEEDED, NetworkAgentJob::FAILED], true)) {
                return $job;
            }
            if ($job->status !== NetworkAgentJob::RUNNING || ! isset($data['success']) || ! is_bool($data['success'])) {
                abort(422);
            }
            $safe = $this->sanitize($data);
            if ($data['success']) {
                $snapshot = $data['snapshot'] ?? null;
                if (! is_array($snapshot)) {
                    abort(422);
                }
                $router = Router::query()->where('id', $job->router_id)->where('tenant_id', $job->tenant_id)->firstOrFail();
                $discovery->persist($router, $job->initiatedBy, new DiscoveryResult(true, (string) ($safe['message'] ?? 'Read-only discovery complete'), null, ['provider' => $safe['provider'] ?? 'unknown', 'discovered_at' => $safe['discovered_at'] ?? now()->toISOString(), 'snapshot' => $snapshot]));
                $job->update(['status' => NetworkAgentJob::SUCCEEDED, 'completed_at' => now(), 'result_meta' => $this->meta($safe), 'error_code' => null, 'error_message' => null]);
            } else {
                $job->update(['status' => NetworkAgentJob::FAILED, 'completed_at' => now(), 'result_meta' => $this->meta($safe), 'error_code' => $safe['code'] ?? 'DISCOVERY_FAILED', 'error_message' => $safe['message'] ?? 'Network agent discovery failed.']);
            }

            return $job;
        });
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

<?php

namespace App\Http\Controllers;

use App\Models\NetworkAgentJob;
use App\Services\Network\NetworkAgentService;
use App\Services\Network\NetworkDiscoveryService;
use App\Services\Network\ObserverReferenceService;
use Illuminate\Http\Request;

class AgentApiController extends Controller
{
    public function heartbeat(Request $request, NetworkAgentService $agents)
    {
        $agent = $this->agent($request, $agents);
        $data = $request->only(['version', 'capabilities', 'go_runtime', 'os', 'architecture', 'uptime_seconds']);
        $agent = $agents->heartbeat($agent, $data);

        return response()->json(['identifier' => $agent->identifier, 'status' => 'ok']);
    }

    public function claim(Request $request, NetworkAgentService $agents)
    {
        $claim = $agents->claim($this->agent($request, $agents));

        return $claim ? response()->json($claim)->header('Cache-Control', 'no-store, private') : response()->noContent();
    }

    public function result(Request $request, NetworkAgentJob $job, NetworkAgentService $agents, NetworkDiscoveryService $discovery)
    {
        $agent = $this->agent($request, $agents);
        $data = $request->validate(['attempt' => ['sometimes', 'integer', 'min:1'], 'fence' => ['sometimes', 'uuid'], 'success' => ['required', 'boolean'], 'provider' => ['nullable', 'string', 'max:100'], 'discovered_at' => ['nullable', 'date'], 'code' => ['nullable', 'string', 'max:100'], 'message' => ['nullable', 'string', 'max:1000'], 'snapshot' => ['nullable', 'array']]);
        $job = $agents->submit($agent, $job, $data, $discovery);

        return response()->json(['id' => $job->id, 'status' => $job->status]);
    }

    public function renew(Request $request, NetworkAgentJob $job, NetworkAgentService $agents)
    {
        $agent = $this->agent($request, $agents);
        $data = $request->validate(['attempt' => ['required', 'integer', 'min:1'], 'fence' => ['required', 'uuid']]);
        $job = $agents->renew($agent, $job, $data);

        return response()->json(['id' => $job->id, 'lease_expires_at' => $job->lease_expires_at]);
    }

    public function syncObserverReference(Request $request, NetworkAgentService $agents, ObserverReferenceService $references)
    {
        $agent = $this->agent($request, $agents);
        abort_unless(array_diff(array_keys($request->all()), ['tenant_ref', 'router_ref', 'agent_ref', 'installation_id', 'credential_ref', 'purpose', 'version', 'status']) === [], 422);
        $payload = $request->validate([
            'tenant_ref' => ['required', 'string', 'max:191'],
            'router_ref' => ['required', 'string', 'max:191'],
            'agent_ref' => ['required', 'string', 'max:191'],
            'installation_id' => ['required', 'string', 'max:191'],
            'credential_ref' => ['required', 'string', 'max:191'],
            'purpose' => ['required', 'in:OBSERVER'],
            'version' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'in:ACTIVE,REVOKED,RETIRED'],
        ]);
        $router = $references->sync($agent, $payload);

        return response()->json(['agent_ref' => $agent->identifier, 'router_ref' => (string) $router->id, 'credential_ref' => $router->observer_credential_ref, 'purpose' => $router->observer_credential_purpose, 'version' => $router->observer_credential_version, 'status' => $router->observer_credential_status, 'migration_state' => $router->observer_migration_state]);
    }

    private function agent(Request $request, NetworkAgentService $agents)
    {
        $agent = $agents->authenticate($request->bearerToken());
        abort_unless($agent, 401);

        return $agent;
    }
}

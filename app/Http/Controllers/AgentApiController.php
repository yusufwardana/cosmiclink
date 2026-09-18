<?php

namespace App\Http\Controllers;

use App\Models\NetworkAgentJob;
use App\Services\Network\NetworkAgentService;
use App\Services\Network\NetworkDiscoveryService;
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

    private function agent(Request $request, NetworkAgentService $agents)
    {
        $agent = $agents->authenticate($request->bearerToken());
        abort_unless($agent, 401);

        return $agent;
    }
}

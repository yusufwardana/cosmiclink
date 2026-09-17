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
        $data = $request->validate(['version' => ['nullable', 'string', 'max:100'], 'capabilities' => ['nullable', 'array']]);
        $agent = $agents->heartbeat($agent, $data);

        return response()->json(['identifier' => $agent->identifier, 'status' => 'ok']);
    }

    public function claim(Request $request, NetworkAgentService $agents)
    {
        $claim = $agents->claim($this->agent($request, $agents));

        return $claim ? response()->json($claim) : response()->noContent();
    }

    public function result(Request $request, NetworkAgentJob $job, NetworkAgentService $agents, NetworkDiscoveryService $discovery)
    {
        $agent = $this->agent($request, $agents);
        $data = $request->validate(['success' => ['required', 'boolean'], 'provider' => ['nullable', 'string', 'max:100'], 'discovered_at' => ['nullable', 'date'], 'code' => ['nullable', 'string', 'max:100'], 'message' => ['nullable', 'string', 'max:1000'], 'snapshot' => ['nullable', 'array']]);
        $job = $agents->submit($agent, $job, $data, $discovery);

        return response()->json(['id' => $job->id, 'status' => $job->status]);
    }

    private function agent(Request $request, NetworkAgentService $agents)
    {
        $agent = $agents->authenticate($request->bearerToken());
        abort_unless($agent, 401);

        return $agent;
    }
}

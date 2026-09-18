<?php

namespace App\Http\Controllers;

use App\Models\NetworkAgent;
use App\Models\NetworkAgentJob;
use App\Services\Network\NetworkAgentFleetService;
use Illuminate\Support\Facades\Auth;

class NetworkAgentController extends Controller
{
    public function index(NetworkAgentFleetService $fleet)
    {
        $tenantId = Auth::user()->tenant_id;

        return view('network.agents', [
            'summary' => $fleet->summary($tenantId),
            'agents' => NetworkAgent::where('tenant_id', $tenantId)
                ->with(['currentJob', 'recentFailure'])
                ->withCount([
                    'jobs as pending_jobs_count' => fn ($query) => $query->where('status', NetworkAgentJob::PENDING),
                    'jobs as running_jobs_count' => fn ($query) => $query->where('status', NetworkAgentJob::RUNNING),
                    'jobs as failed_jobs_count' => fn ($query) => $query->where('status', NetworkAgentJob::FAILED),
                ])
                ->orderBy('name')
                ->paginate(50),
            'jobs' => NetworkAgentJob::where('tenant_id', $tenantId)->with(['agent', 'router'])->latest()->take(30)->get(),
        ]);
    }

    public function show(NetworkAgent $agent, NetworkAgentFleetService $fleet)
    {
        abort_unless($agent->tenant_id === Auth::user()->tenant_id, 404);

        return view('network.agent', $fleet->detail($agent->tenant_id, $agent->id));
    }
}

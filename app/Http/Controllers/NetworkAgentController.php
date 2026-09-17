<?php

namespace App\Http\Controllers;

use App\Models\NetworkAgent;
use App\Models\NetworkAgentJob;
use Illuminate\Support\Facades\Auth;

class NetworkAgentController extends Controller
{
    public function index()
    {
        $tenantId = Auth::user()->tenant_id;

        return view('network.agents', ['agents' => NetworkAgent::where('tenant_id', $tenantId)->withCount(['jobs as pending_jobs_count' => fn ($query) => $query->where('status', NetworkAgentJob::PENDING), 'jobs as running_jobs_count' => fn ($query) => $query->where('status', NetworkAgentJob::RUNNING)])->orderBy('name')->get(), 'jobs' => NetworkAgentJob::where('tenant_id', $tenantId)->with(['agent', 'router'])->latest()->take(30)->get()]);
    }
}

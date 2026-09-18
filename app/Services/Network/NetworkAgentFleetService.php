<?php

namespace App\Services\Network;

use App\Models\NetworkAgent;
use App\Models\NetworkAgentJob;
use Illuminate\Support\Facades\DB;

class NetworkAgentFleetService
{
    public function summary(int $tenantId): array
    {
        $counts = array_fill_keys(['TOTAL', 'ONLINE', 'STALE', 'OFFLINE', 'UNSUPPORTED', 'PENDING', 'RUNNING', 'FAILED'], 0);
        foreach (NetworkAgent::where('tenant_id', $tenantId)->select(['id', 'version', 'last_seen_at'])->cursor() as $agent) {
            $counts['TOTAL']++;
            $counts[app(NetworkAgentHealthService::class)->health($agent)]++;
            if (app(NetworkAgentCompatibilityService::class)->compatibility($agent) === 'UNSUPPORTED') {
                $counts['UNSUPPORTED']++;
            }
        }
        foreach (NetworkAgentJob::where('tenant_id', $tenantId)->selectRaw('status, count(*) as total')->groupBy('status')->get() as $row) {
            if (in_array($row->status, ['PENDING', 'RUNNING', 'FAILED'], true)) {
                $counts[$row->status] = (int) $row->total;
            }
        }

        return $counts;
    }

    public function detail(int $tenantId, int $id): array
    {
        $agent = NetworkAgent::where('tenant_id', $tenantId)->findOrFail($id);
        $jobs = NetworkAgentJob::where('tenant_id', $tenantId)->where('network_agent_id', $id);

        return [
            'agent' => $agent,
            'health' => app(NetworkAgentHealthService::class)->health($agent),
            'compatibility' => app(NetworkAgentCompatibilityService::class)->compatibility($agent),
            'currentJob' => (clone $jobs)->where('status', 'RUNNING')->oldest('id')->first(),
            'jobCounts' => (clone $jobs)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'jobs' => (clone $jobs)->latest('id')->limit(30)->get(),
            'failures' => (clone $jobs)->whereNotNull('error_code')->latest('id')->limit(10)->get(['id', 'error_code']),
            'attempts' => DB::table('network_agent_job_attempts')->where('tenant_id', $tenantId)->where('network_agent_id', $id)->orderByDesc('id')->limit(30)->get(),
            'events' => DB::table('network_agent_events')->where('tenant_id', $tenantId)->where('network_agent_id', $id)->orderByDesc('id')->limit(50)->get(),
        ];
    }
}

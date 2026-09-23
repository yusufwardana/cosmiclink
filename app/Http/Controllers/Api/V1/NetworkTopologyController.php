<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DiscoveredNetworkResource;
use App\Models\HealthObservation;
use App\Models\Router;
use App\Models\TrafficBucket;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Read-only topology for the Network Operations Dashboard.
 * No RouterOS calls, no mutations, no discovery execution.
 */
class NetworkTopologyController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $tenantId  = Auth::user()->tenant_id;
        $routers   = Router::where('tenant_id', $tenantId)
            ->get(['id', 'name', 'host', 'status', 'monitoring_state', 'driver', 'last_seen_at']);
        $routerIds = $routers->pluck('id')->all();

        // Latest health observation per router — one batched read.
        $obs = HealthObservation::where('tenant_id', $tenantId)
            ->where('subject_type', 'router')
            ->whereIn('subject_id', $routerIds)
            ->latest('observed_at')
            ->get()->unique('subject_id')->keyBy('subject_id');

        $routerData = $routers->map(fn ($r) => $this->routerPayload($r, $obs->get($r->id)))->values();

        // Queue + PPPoE discovery resources — one batched read.
        $resources = DiscoveredNetworkResource::where('tenant_id', $tenantId)
            ->whereIn('resource_type', ['queue', 'pppoe_account'])
            ->whereIn('router_id', $routerIds)->orderBy('name')
            ->get(['id', 'router_id', 'resource_type', 'name', 'management_state', 'normalized_data', 'last_seen_at']);

        $subQueues  = $resources->where('resource_type', 'queue')
            ->filter(fn ($r) => $this->isSubscriberTarget($r->normalized_data['target'] ?? null))->values();
        $aggQueues  = $resources->where('resource_type', 'queue')
            ->reject(fn ($r) => $this->isSubscriberTarget($r->normalized_data['target'] ?? null))->values();
        $pppoeRecs  = $resources->where('resource_type', 'pppoe_account')->values();

        // Traffic aggregate — one query for all subscribers.
        $traffic = TrafficBucket::where('tenant_id', $tenantId)
            ->whereIn('router_id', $routerIds)->where('source_type', 'simple_queue')
            ->where('subscriber_authoritative', true)
            ->where('bucket_started_at', '>=', now()->subDays(30))
            ->selectRaw('router_id, subject_key, SUM(upload_bytes) AS up, SUM(download_bytes) AS dn')
            ->groupBy('router_id', 'subject_key')
            ->orderByRaw('SUM(upload_bytes+download_bytes) DESC')->limit(200)
            ->get()->keyBy(fn ($r) => $r->router_id.'|'.$r->subject_key);

        $subNodes   = $subQueues->map(fn ($r) => $this->subPayload($r, $traffic))->values();
        $pppoeNodes = $pppoeRecs->map(fn ($r) => $this->pppoePayload($r))->values();

        $counts = DiscoveredNetworkResource::where('tenant_id', $tenantId)
            ->whereIn('router_id', $routerIds)
            ->selectRaw('resource_type, COUNT(*) AS cnt')
            ->groupBy('resource_type')->pluck('cnt', 'resource_type');

        $iface = TrafficBucket::where('tenant_id', $tenantId)
            ->whereIn('router_id', $routerIds)->where('source_type', 'interface')
            ->where('bucket_started_at', '>=', now()->subHours(24))
            ->selectRaw('router_id, subject_key AS iface, SUM(upload_bytes) AS up, SUM(download_bytes) AS dn')
            ->groupBy('router_id', 'subject_key')
            ->orderByRaw('SUM(upload_bytes+download_bytes) DESC')->limit(10)
            ->get()->map(fn ($r) => [
                'router_id'      => (int) $r->router_id,
                'interface_name' => (string) $r->iface,
                'upload_bytes'   => (int) $r->up,
                'download_bytes' => (int) $r->dn,
            ])->values();

        return response()->json(['data' => [
            'routers'           => $routerData,
            'subscriber_nodes'  => $subNodes,
            'pppoe_nodes'       => $pppoeNodes,
            'aggregate_count'   => $aggQueues->count(),
            'discovery_counts'  => $counts,
            'interface_traffic' => $iface,
        ]]);
    }

    private function routerPayload($r, $obs): array
    {
        $meta = $obs?->metadata ?? [];
        return [
            'id' => $r->id, 'name' => $r->name, 'host' => $r->host, 'status' => $r->status,
            'monitoring_state'    => $obs?->health_state ?? $r->monitoring_state ?? 'unknown',
            'online'              => (bool) ($obs?->online ?? false),
            'reachable'           => (bool) ($obs?->reachable ?? false),
            'observed_at'         => $obs?->observed_at?->toIso8601String(),
            'identity'            => $meta['identity'] ?? null,
            'version'             => $meta['version'] ?? null,
            'board'               => $meta['board'] ?? null,
            'architecture'        => $meta['architecture'] ?? null,
            'uptime_seconds'      => $meta['uptime_seconds'] ?? null,
            'cpu_load_percent'    => $meta['cpu_load_percent'] ?? null,
            'memory_used_percent' => $meta['memory_used_percent'] ?? null,
        ];
    }

    private function subPayload($r, $traffic): array
    {
        $key = $r->router_id.'|'.strtolower(trim($r->normalized_data['target'] ?? ''));
        $t   = $traffic->get($key);
        return [
            'id'               => 'queue-'.$r->id,
            'discovery_id'     => $r->id,
            'router_id'        => $r->router_id,
            'resource_type'    => $r->resource_type,
            'name'             => $r->name,
            'target'           => $r->normalized_data['target'] ?? null,
            'management_state' => $r->management_state,
            'last_seen_at'     => $r->last_seen_at?->toIso8601String(),
            'upload_bytes'     => $t ? (int) $t->up : null,
            'download_bytes'   => $t ? (int) $t->dn : null,
        ];
    }

    private function pppoePayload($r): array
    {
        return [
            'id'               => 'pppoe-'.$r->id,
            'discovery_id'     => $r->id,
            'router_id'        => $r->router_id,
            'resource_type'    => $r->resource_type,
            'name'             => $r->normalized_data['username'] ?? $r->name,
            'target'           => null,
            'management_state' => $r->management_state,
            'last_seen_at'     => $r->last_seen_at?->toIso8601String(),
            'upload_bytes'     => null,
            'download_bytes'   => null,
        ];
    }

    /** True only for single-host /32 IPv4 (no comma, not shorter prefix). */
    private function isSubscriberTarget(?string $t): bool
    {
        if (! is_string($t) || $t === '' || str_contains($t, ',') || ! str_ends_with($t, '/32')) {
            return false;
        }
        $slash = strrpos($t, '/');
        return is_int($slash)
            && filter_var(substr($t, 0, $slash), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }
}

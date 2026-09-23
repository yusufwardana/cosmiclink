<?php

namespace App\Http\Controllers;

use App\Models\CustomerConnection;
use App\Models\DiscoveredNetworkResource;
use App\Models\NetworkAgent;
use App\Models\NetworkAgentJob;
use App\Models\Router;
use App\Services\Network\AdoptDiscoveredNetworkResource;
use App\Services\Network\NetworkAgentService;
use App\Services\Network\NetworkDiscoveryService;
use App\Services\Network\NetworkReconciliationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class NetworkDiscoveryController extends Controller
{
    /*
    | Discovery console grouping. Each group names the persisted
    | `resource_type` values it owns and `other` is the complement, so a type a
    | provider starts emitting later is never silently hidden from the operator.
    */
    public const TYPE_GROUPS = [
        'all' => 'All',
        'queue' => 'Simple Queues',
        'pppoe' => 'PPPoE',
        'hotspot' => 'Hotspot',
        'interface' => 'Interfaces',
        'other' => 'Other',
    ];

    private const GROUP_RESOURCE_TYPES = [
        'queue' => ['queue'],
        'pppoe' => ['pppoe_account'],
        'hotspot' => ['hotspot_user', 'hotspot_active', 'hotspot_profile'],
        'interface' => ['interface', 'interface_ethernet', 'interface_bridge', 'interface_vlan', 'interface_wireless'],
    ];

    public const SORT_OPTIONS = [
        'last_seen_desc' => 'Last seen · newest first',
        'last_seen_asc' => 'Last seen · oldest first',
        'name_asc' => 'Name · A to Z',
        'name_desc' => 'Name · Z to A',
        'type_asc' => 'Resource type · A to Z',
    ];

    public const PER_PAGE_OPTIONS = [25, 50, 100];

    private const STATE_OPTIONS = ['ALL', 'DISCOVERED', 'ADOPTED'];

    /*
    | Presentation labels for the scalar keys the discovery providers persist.
    | Anything a provider returns that is not named here still renders, under a
    | label derived from its own key, so persisted data is never dropped.
    */
    private const FACT_LABELS = [
        'username' => 'Username',
        'profile' => 'Profile',
        'target' => 'Target',
        'max_limit' => 'Max limit',
        'ranges' => 'Ranges',
        'rate_limit' => 'Rate limit',
        'local_address' => 'Local address',
        'remote_address' => 'Remote address',
        'service' => 'Service',
        'enabled' => 'Enabled',
        'comment' => 'Comment',
        'type' => 'Type',
        'status' => 'Status',
        'address' => 'Address',
        'mac_address' => 'MAC address',
        'caller_id' => 'Caller ID',
    ];

    public function index(Request $request, NetworkReconciliationService $reconciliation)
    {
        $tenant = Auth::user()->tenant_id;
        $routers = Router::where('tenant_id', $tenant)->with('discoverySnapshots')->get();
        $resources = DiscoveredNetworkResource::where('tenant_id', $tenant)->with(['router', 'customerConnection.customer', 'networkAccount'])->latest('last_seen_at')->get();
        $latestDiscovery = $routers->mapWithKeys(function (Router $router) {
            $snapshot = $router->discoverySnapshots->where('status', 'success')->sortByDesc('id')->first();

            return [$router->id => $snapshot ? [
                'device' => array_intersect_key((array) ($snapshot->snapshot['device'] ?? []), array_flip(['name', 'routeros_version', 'architecture', 'board_name', 'platform'])),
                'summary' => (array) $snapshot->summary,
                'provider' => (string) $snapshot->provider,
                'discovered_at' => $snapshot->discovered_at,
            ] : null];
        });

        /*
        | Console controls. Every value is validated and then only ever used to
        | narrow the *read* query below: searching, sorting, filtering, or
        | paging never dispatches a discovery run.
        */
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'in:'.implode(',', self::STATE_OPTIONS)],
            'type' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::TYPE_GROUPS))],
            'sort' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::SORT_OPTIONS))],
            'per_page' => ['nullable', 'integer', 'in:'.implode(',', self::PER_PAGE_OPTIONS)],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));
        $state = $filters['state'] ?? 'ALL';
        $group = $filters['type'] ?? 'all';
        $sort = $filters['sort'] ?? 'last_seen_desc';
        $perPage = (int) ($filters['per_page'] ?? self::PER_PAGE_OPTIONS[0]);

        // Persisted inventory, counted once in SQL: these are the numbers the
        // operator reads, so they are never derived from a rendered page.
        $typeCounts = DiscoveredNetworkResource::where('tenant_id', $tenant)
            ->selectRaw('resource_type, count(*) as aggregate')
            ->groupBy('resource_type')
            ->get()
            ->mapWithKeys(fn (DiscoveredNetworkResource $row) => [(string) $row->resource_type => (int) $row->aggregate]);
        $groupCounts = array_fill_keys(array_keys(self::TYPE_GROUPS), 0);
        foreach ($typeCounts as $type => $count) {
            $groupCounts[$this->groupOf($type)] += $count;
        }
        // "All" is the inventory itself, not a group any type can be matched to.
        $totalDiscovered = (int) $typeCounts->sum();
        $groupCounts['all'] = $totalDiscovered;

        $query = DiscoveredNetworkResource::query()->where('tenant_id', $tenant);
        $this->applyGroup($query, $group);
        if ($state !== 'ALL') {
            $query->where('management_state', $state);
        }
        $this->applySearch($query, $search);
        $this->applySort($query, $sort);

        $resources = $query
            ->with(['router', 'customerConnection.customer', 'networkAccount'])
            ->paginate($perPage)
            ->appends(array_filter([
                'q' => $search !== '' ? $search : null,
                'state' => $state !== 'ALL' ? $state : null,
                'type' => $group,
                'sort' => $sort,
                'per_page' => $perPage,
            ], fn ($value) => $value !== null));

        $results = [];
        foreach ($routers as $router) {
            // Read-only classification: this GET renders reconciliation state but
            // must not append reconciliation evidence (see the service contract).
            foreach ($reconciliation->reconcile($router, false) as $result) {
                $results[$result['resource']->id] = $result;
            }
        }

        $connections = CustomerConnection::where('tenant_id', $tenant)->with(['customer', 'networkAccount'])->get();
        $statuses = collect($results)->mapWithKeys(fn (array $result, $id) => [$id => $result['status']]);
        $suggestions = collect($results)->mapWithKeys(fn (array $result, $id) => [$id => $result['suggestion'] ?? null]);

        // The console reads the persisted inventory, never the browser's copy
        // of it: only the current page is presented, and it is presented with
        // the columns its own resource group calls for.
        $rows = $resources->getCollection()->map(fn (DiscoveredNetworkResource $resource) => [
            'resource' => $resource,
            'cells' => $this->tableCells($resource, $group),
            'facts' => $this->detailFacts($resource),
            'status' => $statuses[$resource->id] ?? 'NEW',
            'suggestion' => $suggestions[$resource->id] ?? null,
        ]);

        $sourceRouters = $routers
            ->filter(fn (Router $router) => $latestDiscovery[$router->id] !== null)
            ->sortByDesc(fn (Router $router) => $latestDiscovery[$router->id]['discovered_at']?->getTimestamp() ?? 0)
            ->values();
        $sourceRouter = $sourceRouters->first();

        return view('network.discovery', [
            'routers' => $routers,
            'rows' => $rows,
            'resources' => $resources,
            'reconciliation' => $statuses,
            'suggestions' => $suggestions,
            'connections' => $connections,
            'latestDiscovery' => $latestDiscovery,
            'agents' => NetworkAgent::where('tenant_id', $tenant)->orderBy('name')->get(),
            'agentJobs' => NetworkAgentJob::where('tenant_id', $tenant)->with(['agent', 'router'])->latest()->take(20)->get(),
            'typeGroups' => self::TYPE_GROUPS,
            'groupCounts' => $groupCounts,
            'totalDiscovered' => $totalDiscovered,
            'columns' => $this->tableColumns($group),
            'filters' => ['q' => $search, 'state' => $state, 'type' => $group, 'sort' => $sort, 'per_page' => $perPage],
            'sortOptions' => self::SORT_OPTIONS,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'stateOptions' => self::STATE_OPTIONS,
            'sourceRouter' => $sourceRouter,
            'source' => $sourceRouter ? $latestDiscovery[$sourceRouter->id] : null,
            'sourceRouterCount' => $sourceRouters->count(),
        ]);
    }

    /**
     * The group a persisted `resource_type` belongs to; a type nothing claims
     * lands in `other` rather than disappearing from the console.
     */
    private function groupOf(string $resourceType): string
    {
        foreach (self::GROUP_RESOURCE_TYPES as $group => $types) {
            if (in_array($resourceType, $types, true)) {
                return $group;
            }
        }

        return 'other';
    }

    private function applyGroup(Builder $query, string $group): void
    {
        if ($group === 'all') {
            return;
        }

        if (isset(self::GROUP_RESOURCE_TYPES[$group])) {
            $query->whereIn('resource_type', self::GROUP_RESOURCE_TYPES[$group]);

            return;
        }

        $query->whereNotIn('resource_type', array_merge(...array_values(self::GROUP_RESOURCE_TYPES)));
    }

    /**
     * Search covers the name the operator sees plus the identity, target and
     * address values the providers actually persist in `normalized_data`.
     */
    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

        $query->where(function (Builder $query) use ($like) {
            $query->where('name', 'ilike', $like)->orWhere('external_ref', 'ilike', $like);

            foreach (['username', 'profile', 'target', 'ranges', 'local_address', 'remote_address', 'address', 'mac_address'] as $key) {
                $query->orWhereRaw("normalized_data->>'{$key}' ILIKE ?", [$like]);
            }
        });
    }

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'last_seen_asc' => $query->orderBy('last_seen_at')->orderBy('id'),
            'name_asc' => $query->orderBy('name')->orderBy('id'),
            'name_desc' => $query->orderByDesc('name')->orderByDesc('id'),
            'type_asc' => $query->orderBy('resource_type')->orderBy('name')->orderBy('id'),
            default => $query->orderByDesc('last_seen_at')->orderByDesc('id'),
        };
    }

    private function tableColumns(string $group): array
    {
        return match ($group) {
            'queue' => ['QUEUE NAME', 'TARGET', 'MAX LIMIT', 'STATE', 'LAST SEEN', 'ACTION'],
            'pppoe' => ['USERNAME', 'PROFILE', 'STATE', 'LAST SEEN', 'ACTION'],
            'hotspot' => ['USERNAME', 'IP / MAC', 'STATE', 'LAST SEEN', 'ACTION'],
            'interface' => ['INTERFACE', 'TYPE', 'STATUS', 'LAST SEEN', 'ACTION'],
            default => ['RESOURCE', 'TYPE', 'IDENTITY / TARGET', 'STATE', 'LAST SEEN', 'ACTION'],
        };
    }

    /**
     * Cells for the group's own columns. Every value is read straight out of the
     * persisted row or its `normalized_data`; a field the provider never
     * returned stays null and renders as an em-dash, never as an invented value.
     */
    private function tableCells(DiscoveredNetworkResource $resource, string $group): array
    {
        $data = (array) $resource->normalized_data;

        return match ($group) {
            'queue' => [
                ['value' => $resource->name, 'key' => true],
                ['value' => $data['target'] ?? null, 'mono' => true],
                ['value' => $data['max_limit'] ?? null, 'mono' => true],
            ],
            'pppoe' => [
                ['value' => $data['username'] ?? $resource->name, 'key' => true, 'sub' => $this->pppoeSubline($data)],
                ['value' => $data['profile'] ?? null, 'mono' => true],
            ],
            'hotspot' => [
                ['value' => $data['username'] ?? $resource->name, 'key' => true],
                ['value' => $data['address'] ?? $data['mac_address'] ?? $data['caller_id'] ?? null, 'mono' => true],
            ],
            'interface' => [
                ['value' => $resource->name, 'key' => true],
                ['value' => $data['type'] ?? $resource->resource_type, 'mono' => true],
                ['value' => $this->interfaceStatus($data), 'mono' => true],
            ],
            default => [
                ['value' => $resource->name, 'key' => true],
                ['value' => $resource->resource_type, 'mono' => true],
                ['value' => $this->identity($resource, $data), 'mono' => true],
            ],
        };
    }

    /**
     * The single most identifying value a row carries, for the mixed "All" and
     * "Other" views where one column has to serve several resource types.
     */
    private function identity(DiscoveredNetworkResource $resource, array $data): ?string
    {
        $value = match ($resource->resource_type) {
            'queue' => $data['target'] ?? null,
            'pppoe_account' => $data['username'] ?? $resource->name,
            'pppoe_profile' => ($data['rate_limit'] ?? '') !== '' ? $data['rate_limit'] : ($data['remote_address'] ?? null),
            'address_pool' => $data['ranges'] ?? null,
            default => $data['username'] ?? $data['target'] ?? $data['ranges'] ?? $data['address'] ?? null,
        };

        return $value === null ? null : (string) $value;
    }

    private function pppoeSubline(array $data): ?string
    {
        $bits = [];
        if (array_key_exists('enabled', $data)) {
            $bits[] = $data['enabled'] ? 'ENABLED' : 'DISABLED';
        }
        if (! empty($data['service'])) {
            $bits[] = strtoupper((string) $data['service']);
        }
        if (! empty($data['comment'])) {
            $bits[] = 'comment: '.$data['comment'];
        }

        return $bits === [] ? null : implode(' · ', $bits);
    }

    private function interfaceStatus(array $data): ?string
    {
        $status = $data['status'] ?? $data['running'] ?? $data['disabled'] ?? null;

        if (is_bool($status)) {
            return $status ? 'TRUE' : 'FALSE';
        }

        return $status === null || $status === '' ? null : (string) $status;
    }

    /**
     * Human-readable facts for the detail panel. `external_ref` is identity
     * plumbing and is deliberately left to the collapsed technical section, and
     * nested values stay in the raw payload rather than being flattened into the
     * readable list.
     */
    private function detailFacts(DiscoveredNetworkResource $resource): array
    {
        $data = (array) $resource->normalized_data;
        $ordered = [];

        foreach (array_keys(self::FACT_LABELS) as $key) {
            if (array_key_exists($key, $data)) {
                $ordered[$key] = $data[$key];
            }
        }
        foreach ($data as $key => $value) {
            if (! array_key_exists($key, $ordered) && $key !== 'external_ref' && ! is_array($value)) {
                $ordered[$key] = $value;
            }
        }

        $facts = [];
        foreach ($ordered as $key => $value) {
            $facts[] = [
                'label' => self::FACT_LABELS[$key] ?? ucfirst(str_replace('_', ' ', $key)),
                'value' => $this->factValue($value),
            ];
        }

        return $facts;
    }

    private function factValue(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            $value === null, $value === '' => '—',
            default => (string) $value,
        };
    }



    public function discover(Request $request, Router $router, NetworkDiscoveryService $service, NetworkAgentService $agents)
    {
        Gate::authorize('operate', $router);
        $data = $request->validate(['network_agent_id' => ['nullable', 'integer']]);
        if (! empty($data['network_agent_id'])) {
            $agent = NetworkAgent::where('tenant_id', Auth::user()->tenant_id)->findOrFail($data['network_agent_id']);
            $job = $agents->createDiscoveryJob($router, $agent, Auth::user());

            return back()->with('status', 'Discovery job '.$job->id.' is PENDING for '.$agent->name.'.');
        }
        $snapshot = $service->discover($router, Auth::user());

        return back()->with($snapshot->status === 'success' ? 'status' : 'error', $snapshot->status === 'success' ? 'Read-only discovery completed.' : $snapshot->error);
    }

    public function adopt(Request $request, int $resource, AdoptDiscoveredNetworkResource $adopt)
    {
        $resource = DiscoveredNetworkResource::find($resource);
        abort_unless($resource && $resource->tenant_id === Auth::user()->tenant_id, 403);
        $data = $request->validate(['customer_connection_id' => ['required', 'integer']]);
        $connection = CustomerConnection::find($data['customer_connection_id']);
        abort_unless($connection && $connection->tenant_id === Auth::user()->tenant_id, 403);
        $adopt->handle($resource, $connection, Auth::user());

        return back()->with('status', 'Existing network resource adopted without router changes.');
    }

    public function unadopt(Request $request, int $resource, AdoptDiscoveredNetworkResource $adopt)
    {
        $resource = DiscoveredNetworkResource::find($resource);
        abort_unless($resource && $resource->tenant_id === Auth::user()->tenant_id, 403);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $adopt->unadopt($resource, Auth::user(), $data['reason'] ?? null);

        return back()->with('status', 'Local adoption reversed without router changes.');
    }
}

@extends('layouts.app')
@section('content')
@php
    /*
     | Discovery console. Every number and every cell below is read from the
     | persisted discovery rows for this tenant: the page filters, sorts and
     | pages that inventory server-side and never dispatches a discovery run.
     */
    $keptFilters = array_filter([
        'q' => $filters['q'] !== '' ? $filters['q'] : null,
        'state' => $filters['state'] !== 'ALL' ? $filters['state'] : null,
        'sort' => $filters['sort'] !== 'last_seen_desc' ? $filters['sort'] : null,
        'per_page' => $filters['per_page'] !== 25 ? $filters['per_page'] : null,
    ], fn ($value) => $value !== null);
    $tabUrl = fn (string $key) => route('network.discovery.index', $keptFilters + ['type' => $key]);
    $sourceIsReal = ($source['provider'] ?? 'fake') !== 'fake';
    $groupHints = [
        'all' => 'Every persisted discovery row',
        'queue' => 'Simple queue targets',
        'pppoe' => 'PPPoE account identities',
        'hotspot' => 'Hotspot users and sessions',
        'interface' => 'Router interfaces',
        'other' => 'Profiles, pools and ungrouped types',
    ];
    $currentPage = $resources->currentPage();
    $lastPage = $resources->lastPage();
    $windowStart = max(1, min($currentPage - 2, max(1, $lastPage - 4)));
    $windowEnd = min($lastPage, $windowStart + 4);
    $pageNumbers = $windowStart <= $windowEnd ? range($windowStart, $windowEnd) : [];
@endphp
<div class="page-header"><div><p class="eyebrow">[DISCOVERY] · Network</p><h1>Read-only discovery</h1><p class="page-header__meta">No router configuration will be modified. Provider: {{ strtoupper((string) config('network.discovery_provider')) }}. Adoption maps an existing PPPoE account locally.</p></div><div class="page-header__aside"><span class="topbar__mode">READ-ONLY</span></div></div>
<section class="panel">
    <div class="panel__head"><div><p class="panel__kicker">Routers</p><h2 class="panel__title">Run discovery</h2></div><span class="panel__meta">{{ $routers->count() }} router{{ $routers->count() === 1 ? '' : 's' }}</span></div>
    <div class="panel__body">
        @forelse($routers as $router)
            @php($discovery = $latestDiscovery[$router->id])
            <form class="discovery-run" method="post" action="{{ route('network.discovery.run', $router) }}">
                @csrf
                <span class="discovery-run__router">{{ $router->name }}</span>
                <select name="network_agent_id" aria-label="Executor for {{ $router->name }}">
                    <option value="">Direct development path</option>
                    @foreach($agents as $agent)
                        <option value="{{ $agent->id }}">{{ $agent->name }} · {{ $agent->isOnline() ? 'ONLINE' : 'OFFLINE' }}</option>
                    @endforeach
                </select>
                <button class="button--quiet button--sm">Queue / Run Discovery</button>
                @if($discovery)
                    <p class="console-note discovery-run__device">
                        Device: {{ $discovery['device']['name'] ?? 'Unknown' }}
                        @if(! empty($discovery['device']['board_name'])) · {{ $discovery['device']['board_name'] }} @endif
                        @if(! empty($discovery['device']['architecture'])) · {{ $discovery['device']['architecture'] }} @endif
                        @if(! empty($discovery['device']['routeros_version'])) · RouterOS: {{ $discovery['device']['routeros_version'] }} @endif
                        · Profiles: {{ $discovery['summary']['profiles'] ?? 0 }} · Accounts: {{ $discovery['summary']['accounts'] ?? 0 }} · Pools: {{ $discovery['summary']['address_pools'] ?? 0 }} · Queues: {{ $discovery['summary']['queues'] ?? 0 }}
                    </p>
                @else
                    <p class="console-note discovery-run__device">No successful read-only discovery recorded for this router yet.</p>
                @endif
            </form>
        @empty
            <p class="empty-state">No router is registered for this tenant.</p>
        @endforelse
    </div>
</section>
<section class="panel discovery-console">
    <div class="panel__head">
        <div><p class="panel__kicker">Observed resources</p><h2 class="panel__title">Discovered inventory</h2><p class="panel__meta">Adoption changes CosmicLink only. RouterOS remains read-only.</p></div>
        <span class="panel__meta">{{ number_format($totalDiscovered) }} persisted</span>
    </div>
    <div class="discovery-source">
        <span class="ui-status-badge {{ $sourceIsReal ? 'ui-status-badge--online' : 'ui-status-badge--degraded' }}">{{ $sourceIsReal ? 'REAL' : 'SIMULATED' }}</span>
        @if($sourceRouter)
            <span class="discovery-source__item"><span class="discovery-source__label">Router</span><span class="discovery-source__value">{{ $sourceRouter->name }}</span></span>
            <span class="discovery-source__item"><span class="discovery-source__label">Address</span><span class="discovery-source__value">{{ $sourceRouter->host }}</span></span>
            <span class="discovery-source__item"><span class="discovery-source__label">Provider</span><span class="discovery-source__value">{{ strtoupper($source['provider'] ?? 'unknown') }}</span></span>
            @if(! empty($source['device']['routeros_version']))
                <span class="discovery-source__item"><span class="discovery-source__label">RouterOS</span><span class="discovery-source__value">{{ $source['device']['routeros_version'] }}</span></span>
            @endif
            @if(! empty($source['device']['board_name']))
                <span class="discovery-source__item"><span class="discovery-source__label">Board</span><span class="discovery-source__value">{{ $source['device']['board_name'] }}</span></span>
            @endif
            @if($source['discovered_at'] ?? null)
                <span class="discovery-source__item"><span class="discovery-source__label">Last discovery</span><span class="discovery-source__value">{{ $source['discovered_at']->diffForHumans() }}</span></span>
            @endif
            @if($sourceRouterCount > 1)
                <span class="discovery-source__item"><span class="discovery-source__label">Other routers</span><span class="discovery-source__value">+{{ $sourceRouterCount - 1 }}</span></span>
            @endif
        @else
            <span class="discovery-source__item"><span class="discovery-source__label">Source</span><span class="discovery-source__value">No successful read-only discovery recorded yet</span></span>
        @endif
    </div>
    <div class="stat-grid stat-grid--discovery">
        @foreach($typeGroups as $key => $label)
            <a class="stat-card stat-card--interactive" href="{{ $tabUrl($key) }}" @if($filters['type'] === $key) aria-current="true" @endif>
                <span class="stat-card__label">{{ $key === 'all' ? 'Total discovered' : $label }}</span>
                <span class="stat-card__value">{{ number_format($groupCounts[$key]) }}</span>
                <span class="stat-card__hint">{{ $groupHints[$key] }}</span>
            </a>
        @endforeach
    </div>
    <nav class="discovery-tabs" aria-label="Resource type">
        @foreach($typeGroups as $key => $label)
            <a class="discovery-tab @if($filters['type'] === $key) discovery-tab--active @endif" href="{{ $tabUrl($key) }}" @if($filters['type'] === $key) aria-current="page" @endif>{{ $label }}<span class="discovery-tab__count">{{ number_format($groupCounts[$key]) }}</span></a>
        @endforeach
    </nav>
    <form class="discovery-filters" method="get" action="{{ route('network.discovery.index') }}">
        <label class="discovery-filters__search">Search
            <input type="search" name="q" value="{{ $filters['q'] }}" maxlength="100" placeholder="Name, username, target, IP or reference">
        </label>
        <label>State
            <select name="state">
                @foreach($stateOptions as $option)
                    <option value="{{ $option }}" @selected($filters['state'] === $option)>{{ $option }}</option>
                @endforeach
            </select>
        </label>
        <label>Resource type
            <select name="type">
                @foreach($typeGroups as $key => $label)
                    <option value="{{ $key }}" @selected($filters['type'] === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label>Sort
            <select name="sort">
                @foreach($sortOptions as $key => $label)
                    <option value="{{ $key }}" @selected($filters['sort'] === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label>Rows
            <select name="per_page">
                @foreach($perPageOptions as $option)
                    <option value="{{ $option }}" @selected($filters['per_page'] === $option)>{{ $option }}</option>
                @endforeach
            </select>
        </label>
        <button class="button--primary button--sm" type="submit">Apply</button>
        <a class="button--quiet button--sm" href="{{ route('network.discovery.index') }}">Reset</a>
    </form>

    <div class="panel__body panel__body--flush">
        @if(session('bulk_adoption_results'))
            @php($bulk = session('bulk_adoption_results'))
            <div class="panel__body"><p class="console-note">Bulk adoption completed · Adopted: {{ count($bulk['adopted']) }} · Skipped: {{ count($bulk['skipped']) }} · Failed: {{ count($bulk['failed']) }}</p></div>
        @endif
        @if($bulkEligibleOnPage)
            <form method="post" action="{{ route('network.discovery.bulk-review') }}" id="bulk-discovery-form">@csrf</form>
            <div class="discovery-bulk-bar" data-bulk-bar>
                <div class="discovery-bulk-bar__count"><strong data-bulk-count>0 selected</strong><span class="console-note">current page only · max 50 per operation</span><span class="field-error" data-bulk-limit-message hidden>Select no more than 50 identities per bulk operation.</span></div>
                <div class="discovery-bulk-bar__actions">
                    <button class="button button--quiet button--sm" type="button" data-bulk-select>Select Eligible</button>
                    <button class="button button--quiet button--sm" type="button" data-bulk-clear>Clear</button>
                    <button class="button button--primary button--sm" type="submit" form="bulk-discovery-form" data-bulk-submit disabled>Adopt Selected Customers</button>
                </div>
            </div>
        @endif
        <div class="table-scroll">
            <table class="discovery-table">
                <thead><tr>@if($bulkEligibleOnPage)<th class="discovery-select-column"><span class="sr-only">Select</span></th>@endif @foreach($columns as $column)<th>{{ $column }}</th>@endforeach</tr></thead>
                <tbody>
                @forelse($rows as $row)
                    @php($resource = $row['resource'])
                    <tr class="discovery-row" data-detail-row="{{ $resource->id }}">
                        @foreach($row['cells'] as $cell)
                            <td>
                                <span class="{{ ! empty($cell['key']) ? 'cell-key' : (! empty($cell['mono']) ? 'mono-value' : '') }}">{{ $cell['value'] ?? '—' }}</span>
                                @if(! empty($cell['sub']))<span class="cell-sub">{{ $cell['sub'] }}</span>@endif
                            </td>
                        @endforeach
                        @if($bulkEligibleOnPage)
                            <td class="discovery-select-column">
                                @if($row['eligible_for_bulk'] ?? false)
                                    <input class="discovery-bulk-checkbox" type="checkbox" name="resource_ids[]" value="{{ $resource->id }}" aria-label="Select {{ $resource->name }}" form="bulk-discovery-form">
                                @endif
                            </td>
                        @endif
                        <td>
                            <span class="ui-status-badge {{ $resource->management_state === 'ADOPTED' ? 'ui-status-badge--active' : 'ui-status-badge--unknown' }}">{{ $resource->management_state }}</span>
                            <span class="cell-sub mono-value">[{{ $row['status'] }}] on {{ $resource->router?->name ?? '—' }}</span>
                        </td>
                        <td><time datetime="{{ $resource->last_seen_at?->toIso8601String() }}" title="{{ $resource->last_seen_at?->format('Y-m-d H:i:s') ?? '' }}">{{ $resource->last_seen_at?->diffForHumans() ?? '—' }}</time></td>
                        <td>
                            <button class="button--quiet button--sm" type="button" data-detail-open="{{ $resource->id }}">View</button>
                            @if($resource->resource_type === 'queue' && ! str_contains((string) ($resource->normalized_data['target'] ?? ''), ',') && str_ends_with((string) ($resource->normalized_data['target'] ?? ''), '/32') && $resource->management_state === 'DISCOVERED')
                                <form method="post" action="{{ route('network.discovery.customers.store', $resource) }}" class="inline-form" style="margin-top:6px">
                                    @csrf
                                    <input type="hidden" name="name" value="{{ $resource->name }}">
                                    <input type="hidden" name="status" value="active">
                                    <button class="button--primary button--sm" type="submit">Create / Link Customer</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($columns) }}">
                        @if($totalDiscovered === 0)
                            <p class="empty-state">No discovered resource has been persisted yet. Run a read-only discovery above; nothing is written to the router.</p>
                        @elseif($groupCounts[$filters['type']] === 0 && $filters['q'] === '' && $filters['state'] === 'ALL')
                            <p class="empty-state">No {{ $typeGroups[$filters['type']] }} resource has been persisted for this tenant yet. Discovery is read-only, so this view stays empty until the router reports one.</p>
                        @else
                            <p class="empty-state">No persisted resource matches the current search, state and type filters. Filtering never runs a new discovery.</p>
                        @endif
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="discovery-pager">
        <p class="console-toolbar__note">
            @if($resources->total() > 0)
                Showing {{ $resources->firstItem() }}–{{ $resources->lastItem() }} of {{ number_format($resources->total()) }} persisted resources
            @else
                No persisted resource in this selection
            @endif
            @if($lastPage > 1) · page {{ $currentPage }} of {{ $lastPage }} @endif
        </p>
        @if($lastPage > 1)
            <nav class="discovery-pager__pages" aria-label="Discovery pages">
                @if($resources->onFirstPage())
                    <span class="discovery-pager__link discovery-pager__link--disabled">Prev</span>
                @else
                    <a class="discovery-pager__link" href="{{ $resources->previousPageUrl() }}" rel="prev">Prev</a>
                @endif
                @if($windowStart > 1)
                    <a class="discovery-pager__link" href="{{ $resources->url(1) }}">1</a>
                    @if($windowStart > 2)<span class="discovery-pager__gap">…</span>@endif
                @endif
                @foreach($pageNumbers as $page)
                    @if($page === $currentPage)
                        <span class="discovery-pager__link discovery-pager__link--current" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="discovery-pager__link" href="{{ $resources->url($page) }}">{{ $page }}</a>
                    @endif
                @endforeach
                @if($windowEnd < $lastPage)
                    @if($windowEnd < $lastPage - 1)<span class="discovery-pager__gap">…</span>@endif
                    <a class="discovery-pager__link" href="{{ $resources->url($lastPage) }}">{{ $lastPage }}</a>
                @endif
                @if($resources->hasMorePages())
                    <a class="discovery-pager__link" href="{{ $resources->nextPageUrl() }}" rel="next">Next</a>
                @else
                    <span class="discovery-pager__link discovery-pager__link--disabled">Next</span>
                @endif
            </nav>
        @endif
    </div>
</section>
<section class="panel" id="import-customers">
    <div class="panel__head"><div><p class="panel__kicker">Customer reconstruction</p><h2 class="panel__title">Import Customers</h2><p class="panel__meta">Explicit local import only. RouterOS remains read-only and aggregate/system queues are excluded.</p></div><span class="panel__meta">{{ count($importCandidates['simple']) + count($importCandidates['hotspot']) }} candidates</span></div>
    <div class="panel__body">
        <form method="post" action="{{ route('network.discovery.import.review') }}">
            @csrf
            <h3>Simple Queue candidates</h3>
            @forelse(collect($importCandidates['simple'])->where('state', 'READY') as $candidate)
                <label class="discovery-import-candidate"><input type="checkbox" name="candidates[]" value="{{ $candidate['key'] }}"> <strong>{{ $candidate['default_name'] }}</strong> <span class="mono-value">{{ $candidate['network_identity'] }}</span></label>
            @empty
                <p class="console-note">No Simple Queue candidates.</p>
            @endforelse
            <h3>Hotspot monthly candidates</h3>
            @forelse(collect($importCandidates['hotspot'])->where('state', 'READY') as $candidate)
                <label class="discovery-import-candidate"><input type="checkbox" name="candidates[]" value="{{ $candidate['key'] }}"> <strong>{{ $candidate['name'] }}</strong> <span class="mono-value">{{ implode(', ', $candidate['discovery_evidence']) }}</span> <span class="field-hint">one Customer per username</span></label>
            @empty
                <p class="console-note">No Hotspot monthly candidates.</p>
            @endforelse
            <button class="button--primary button--sm" type="submit">Review Selected Imports</button>
        </form>
    </div>
</section>

<section class="panel">
    <div class="panel__head"><div><p class="panel__kicker">Agent work</p><h2 class="panel__title">Asynchronous discovery jobs</h2></div><a class="button--quiet button--sm" href="{{ route('network.agents.index') }}">Agents</a></div>
    <div class="panel__body panel__body--flush"><div class="table-scroll"><table><thead><tr><th>Job</th><th>Agent</th><th>Router</th><th>Status</th></tr></thead><tbody>@forelse($agentJobs as $job)<tr><td class="mono-value">[JOB] {{ $job->job_type }}</td><td>{{ $job->agent->name }}</td><td>{{ $job->router->name }}</td><td><span class="ui-status-badge">{{ $job->status }}</span></td></tr>@empty<tr><td colspan="4"><p class="empty-state">No agent discovery jobs yet.</p></td></tr>@endforelse</tbody></table></div></div>
</section>
{{-- Detail payloads for the current page only: the table never carries a JSON
     blob, and the drawer clones the block for the row the operator opened. --}}
@foreach($rows as $row)
    @php($resource = $row['resource'])
    <div class="discovery-detail" id="discovery-detail-{{ $resource->id }}" data-title="{{ $resource->name }}" data-kind="{{ $resource->resource_type }} · {{ $resource->management_state }}">
        <dl class="spec discovery-detail__facts">
            <dt>Resource type</dt><dd class="mono-value">{{ $resource->resource_type }}</dd>
            <dt>Router</dt><dd>{{ $resource->router?->name ?? '—' }}</dd>
            <dt>Management state</dt><dd>{{ $resource->management_state }}<em>{{ $resource->management_state === 'ADOPTED' ? 'Adopted locally; network control stays disabled and no lifecycle state is implied.' : 'Observed only. Adoption is an explicit operator action.' }}</em></dd>
            <dt>Reconciliation</dt><dd class="mono-value">[{{ $row['status'] }}]</dd>
            @if($row['suggestion'])
                <dt>Suggested match</dt><dd>{{ $row['suggestion']['customer'] ?? 'Existing connection' }}<em>{{ $row['suggestion']['reason'] ?? '' }}</em></dd>
            @endif
            <dt>First seen</dt><dd class="mono-value">{{ $resource->first_seen_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
            <dt>Last seen</dt><dd class="mono-value">{{ $resource->last_seen_at?->format('Y-m-d H:i:s') ?? '—' }} ({{ $resource->last_seen_at?->diffForHumans() ?? '—' }})</dd>
            @foreach($row['facts'] as $fact)
                <dt>{{ $fact['label'] }}</dt><dd class="mono-value">{{ $fact['value'] }}</dd>
            @endforeach
        </dl>
        @if($resource->resource_type === 'pppoe_account')
            @php($routerConnections = $connections->where('router_id', $resource->router_id))
            <div class="discovery-adopt">
                @if($resource->management_state === 'DISCOVERED')
                    <h3 class="discovery-adopt__title">Adopt into a customer connection</h3>
                    @if($routerConnections->isEmpty())
                        <p class="field-hint">No customer connection exists on {{ $resource->router?->name ?? 'this router' }} yet, and adoption needs an existing local connection. Create one first. Nothing was changed on RouterOS.</p>
                    @else
                        <form method="post" action="{{ route('network.discovery.adopt', $resource) }}">
                            @csrf
                            <label>Target connection
                                <select name="customer_connection_id" required>
                                    <option value="">Select a connection</option>
                                    @foreach($routerConnections as $connection)
                                        <option value="{{ $connection->id }}" @selected(($row['suggestion']['customer_connection_id'] ?? null) === $connection->id)>{{ $connection->customer?->name ?? 'Customer' }} / {{ $connection->connection_code }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <button class="button--primary button--sm" type="submit" onclick="return confirm('Confirm local adoption. This will not modify RouterOS, credentials, sessions, or traffic.')">Adopt locally</button>
                        </form>
                        <p class="field-hint">Adoption writes to CosmicLink only. It never provisions, enables, disables, or changes anything on the router.</p>
                    @endif
                @else
                    <h3 class="discovery-adopt__title">Reverse local adoption</h3>
                    <form method="post" action="{{ route('network.discovery.unadopt', $resource) }}">
                        @csrf
                        <label>Reason (optional)<input type="text" name="reason" maxlength="500" value=""></label>
                        <button class="button--danger button--sm" type="submit" onclick="return confirm('Reverse the local adoption? RouterOS is not touched.')">Reverse adoption</button>
                    </form>
                    <p class="field-hint">Reversing clears the CosmicLink mapping and its target identity context. The router is never touched.</p>
                @endif
            </div>
        @else
            <p class="console-note">Only PPPoE account resources have an adoption path in Phase 6H. This resource stays read-only evidence in CosmicLink, and no bulk adoption exists.</p>
        @endif
        <details class="discovery-technical">
            <summary>Technical details</summary>
            <div class="discovery-technical__body">
                <dl class="spec">
                    <dt>Resource id</dt><dd class="mono-value">{{ $resource->id }}</dd>
                    <dt>External reference</dt><dd class="mono-value">{{ $resource->external_ref }}</dd>
                    <dt>Fingerprint</dt><dd class="mono-value">{{ $resource->fingerprint }}</dd>
                    <dt>Discovery snapshot</dt><dd class="mono-value">{{ $resource->discovery_snapshot_id ?? '—' }}</dd>
                    <dt>Router</dt><dd class="mono-value">{{ $resource->router_id }} · {{ $resource->router?->host ?? '—' }}</dd>
                    <dt>First seen (UTC)</dt><dd class="mono-value">{{ $resource->first_seen_at?->toIso8601String() ?? '—' }}</dd>
                    <dt>Last seen (UTC)</dt><dd class="mono-value">{{ $resource->last_seen_at?->toIso8601String() ?? '—' }}</dd>
                    @if($resource->customer_connection_id)
                        <dt>Adopted connection</dt><dd class="mono-value">{{ $resource->customer_connection_id }} · {{ $resource->customerConnection?->connection_code ?? '—' }}</dd>
                    @endif
                    @if($resource->network_account_id)
                        <dt>Network account</dt><dd class="mono-value">{{ $resource->network_account_id }} · {{ $resource->networkAccount?->username ?? '—' }}</dd>
                    @endif
                </dl>
                <pre class="discovery-code">{{ json_encode($resource->normalized_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            </div>
        </details>
    </div>
@endforeach

<dialog class="discovery-drawer" id="discovery-drawer" aria-labelledby="discovery-drawer-title">
    <div class="discovery-drawer__head">
        <div>
            <p class="panel__kicker" data-drawer-kind></p>
            <h2 class="panel__title" id="discovery-drawer-title" data-drawer-title></h2>
        </div>
        <button class="button--quiet button--sm" type="button" data-drawer-close aria-label="Close resource details">Close</button>
    </div>
    <div class="discovery-drawer__body" data-drawer-scroll>
        <div class="discovery-drawer__mount" data-drawer-body></div>
    </div>
</dialog>
<script>
    /* Progressive enhancement only: without it the page is a complete, paged,
       server-rendered console and the detail payloads simply stay hidden. */
    (() => {
        const drawer = document.getElementById('discovery-drawer');
        if (!drawer || typeof drawer.showModal !== 'function') { return; }
        const mount = drawer.querySelector('[data-drawer-body]');
        const scroll = drawer.querySelector('[data-drawer-scroll]');
        const title = drawer.querySelector('[data-drawer-title]');
        const kind = drawer.querySelector('[data-drawer-kind]');

        const open = (id) => {
            const source = document.getElementById('discovery-detail-' + id);
            if (!source || !mount) { return; }
            mount.replaceChildren(...Array.from(source.children, (node) => node.cloneNode(true)));
            if (title) { title.textContent = source.dataset.title || ''; }
            if (kind) { kind.textContent = source.dataset.kind || ''; }
            drawer.showModal();
            if (scroll) { scroll.scrollTop = 0; }
        };

        document.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof Element)) { return; }
            const opener = target.closest('[data-detail-open]');
            if (opener) { event.preventDefault(); open(opener.dataset.detailOpen); return; }
            if (target.closest('[data-drawer-close]')) { drawer.close(); return; }
            const row = target.closest('[data-detail-row]');
            if (row && !target.closest('a, button, input, select, textarea, summary, label')) { open(row.dataset.detailRow); }
        });

        drawer.addEventListener('click', (event) => { if (event.target === drawer) { drawer.close(); } });
    })();
</script>
        @if($bulkEligibleOnPage)
<script>
(() => {
    const bar = document.querySelector('[data-bulk-bar]');
    if (!bar) return;
    const MAX_BULK_SELECTION = 50;
    const boxes = () => [...document.querySelectorAll('.discovery-bulk-checkbox')];
    const count = bar.querySelector('[data-bulk-count]');
    const submit = bar.querySelector('[data-bulk-submit]');
    const limitMessage = bar.querySelector('[data-bulk-limit-message]');
    const update = () => {
        const selectedBoxes = boxes().filter((box) => box.checked);
        const selected = selectedBoxes.length;
        count.textContent = `${selected} selected`;
        submit.disabled = selected === 0;
        if (limitMessage) limitMessage.hidden = selected <= MAX_BULK_SELECTION;
    };
    bar.querySelector('[data-bulk-select]').addEventListener('click', () => { boxes().forEach((box, index) => { box.checked = index < MAX_BULK_SELECTION; }); update(); });
    bar.querySelector('[data-bulk-clear]').addEventListener('click', () => { boxes().forEach((box) => { box.checked = false; }); update(); });
    boxes().forEach((box) => box.addEventListener('change', () => {
        const selected = boxes().filter((item) => item.checked);
        if (selected.length > MAX_BULK_SELECTION) box.checked = false;
        update();
    }));
    update();
})();
</script>
@endif
@endsection

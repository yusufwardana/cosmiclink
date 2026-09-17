@extends('layouts.app')
@section('content')
<div class="page-header"><div><p class="eyebrow">[DISCOVERY] · Network</p><h1>Read-only discovery</h1><p class="page-header__meta">No router configuration will be modified. Provider: {{ strtoupper((string) config('network.discovery_provider')) }}. Adoption maps an existing PPPoE account locally.</p></div><div class="page-header__aside"><span class="topbar__mode">READ-ONLY</span></div></div>
<section class="panel">
    <div class="panel__head"><div><p class="panel__kicker">Routers</p><h2 class="panel__title">Run discovery</h2></div></div>
    <div class="panel__body">
        @foreach($routers as $router)
            <form class="inline-form" method="post" action="{{ route('network.discovery.run', $router) }}">
                @csrf <span class="mono-value">{{ $router->name }}</span><select name="network_agent_id"><option value="">Direct development path</option>@foreach($agents as $agent)<option value="{{ $agent->id }}">{{ $agent->name }} · {{ $agent->isOnline() ? 'ONLINE' : 'OFFLINE' }}</option>@endforeach</select><button class="button--quiet button--sm">Queue / Run Discovery</button>
            </form>
            @php($discovery = $latestDiscovery[$router->id])
            @if($discovery)
                <p class="console-note">
                    Device: {{ $discovery['device']['name'] ?? 'Unknown' }}
                    @if(! empty($discovery['device']['board_name'])) · {{ $discovery['device']['board_name'] }} @endif
                    @if(! empty($discovery['device']['architecture'])) · {{ $discovery['device']['architecture'] }} @endif
                    @if(! empty($discovery['device']['routeros_version'])) · RouterOS: {{ $discovery['device']['routeros_version'] }} @endif
                    · Profiles: {{ $discovery['summary']['profiles'] ?? 0 }} · Accounts: {{ $discovery['summary']['accounts'] ?? 0 }} · Pools: {{ $discovery['summary']['address_pools'] ?? 0 }} · Queues: {{ $discovery['summary']['queues'] ?? 0 }}
                </p>
            @endif
        @endforeach
    </div>
</section>
<section class="panel"><div class="panel__head"><div><p class="panel__kicker">Agent work</p><h2 class="panel__title">Asynchronous discovery jobs</h2></div><a class="button--quiet button--sm" href="{{ route('network.agents.index') }}">Agents</a></div><div class="panel__body panel__body--flush"><div class="table-scroll"><table><thead><tr><th>Job</th><th>Agent</th><th>Router</th><th>Status</th></tr></thead><tbody>@forelse($agentJobs as $job)<tr><td class="mono-value">[JOB] {{ $job->job_type }}</td><td>{{ $job->agent->name }}</td><td>{{ $job->router->name }}</td><td><span class="ui-status-badge">{{ $job->status }}</span></td></tr>@empty<tr><td colspan="4"><p class="empty-state">No agent discovery jobs yet.</p></td></tr>@endforelse</tbody></table></div></div></section>
<section class="panel"><div class="panel__head"><div><p class="panel__kicker">Observed resources</p><h2 class="panel__title">Compare and adopt</h2></div><span class="panel__meta">{{ $resources->count() }} resources</span></div><div class="panel__body panel__body--flush"><div class="table-scroll"><table><thead><tr><th>Resource</th><th>Router</th><th>State</th><th>Reconciliation</th><th>Action</th></tr></thead><tbody>@forelse($resources as $resource)<tr><td class="cell-key">{{ $resource->name }}<span class="cell-sub">{{ $resource->resource_type }}</span></td><td>{{ $resource->router->name }}</td><td><span class="ui-status-badge">{{ $resource->management_state }}</span></td><td><span class="mono-value">[{{ $reconciliation[$resource->id] }}]</span></td><td>@if($resource->resource_type==='pppoe_account'&&$resource->management_state==='DISCOVERED')<form class="inline-form" method="post" action="{{ route('network.discovery.adopt',$resource) }}">@csrf <select name="customer_connection_id" required><option value="">Map connection</option>@foreach($connections->where('router_id',$resource->router_id) as $connection)<option value="{{ $connection->id }}">{{ $connection->connection_code }}</option>@endforeach</select><button class="button--quiet button--sm">Adopt</button></form>@else — @endif</td></tr>@empty<tr><td colspan="5"><p class="empty-state">No discovery evidence yet.</p></td></tr>@endforelse</tbody></table></div></div></section>
@endsection
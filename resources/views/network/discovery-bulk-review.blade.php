@extends('layouts.app')
@section('content')
<div class="page-header"><div><p class="eyebrow">[DISCOVERY] · Bulk adoption</p><h1>Review Customer Adoption</h1><p class="page-header__meta">Confirming creates local Customers and CustomerConnections only. RouterOS changes: NONE.</p></div></div>
<section class="panel">
    <div class="panel__head"><div><p class="panel__kicker">Bulk Customer Adoption</p><h2 class="panel__title">{{ $resources->count() }} selected</h2></div><span class="topbar__mode">ADOPTED · CONTROL DISABLED</span></div>
    <div class="panel__body">
        <div class="table-scroll"><table><thead><tr><th>Customer</th><th>Target</th><th>Router</th><th>State</th></tr></thead><tbody>@foreach($resources as $resource)<tr><td>{{ $resource->name }}</td><td class="mono-value">{{ $resource->normalized_data['target'] ?? '—' }}</td><td>{{ $resource->router?->name ?? '—' }}</td><td>{{ $resource->management_state }}</td></tr>@endforeach</tbody></table></div>
        <form method="post" action="{{ route('network.discovery.bulk-adopt') }}" class="settings-form__actions">@csrf @foreach($resources as $resource)<input type="hidden" name="resource_ids[]" value="{{ $resource->id }}">@endforeach <a class="button button--quiet" href="{{ route('network.discovery.index', ['type' => 'queue']) }}">Cancel</a><button class="button button--primary" type="submit">Confirm Adoption</button></form>
    </div>
</section>
@endsection
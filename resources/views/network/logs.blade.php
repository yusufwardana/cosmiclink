@extends('layouts.app')

{{-- Hallmark · genre: editorial · macrostructure: Index-First (13) · design-system: design.md · designed-as-app --}}
@section('content')
    <div class="page-header">
        <div>
            <p class="eyebrow"><span class="eyebrow__ord">13</span><span class="eyebrow__sep">·</span>Operations</p>
            <h1>Operation logs</h1>
            <p class="page-header__meta">The network driver's audit trail: requested operation, target, result and timestamp. Tenant-scoped and append-only from the operator's point of view.</p>
        </div>
        <div class="page-header__aside"><span class="panel__meta">{{ $logs->count() }} operations</span></div>
    </div>

    <section class="panel" aria-labelledby="operation-ledger">
        <div class="panel__head"><div><p class="panel__kicker">Ledger</p><h2 class="panel__title" id="operation-ledger">Network operations</h2></div><span class="panel__meta">newest first</span></div>
        <div class="panel__body panel__body--flush">@include('network._logs', ['logs' => $logs])</div>
    </section>
@endsection
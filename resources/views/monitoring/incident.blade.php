@extends('layouts.app')

@section('content')
<div class="page-header">
    <div>
        <p class="eyebrow"><span class="eyebrow__ord">01</span><span class="eyebrow__sep">·</span>Outage intelligence</p>
        <h1>Incident {{ $incident->id }} · {{ $incident->router?->name ?? 'Unassigned router' }}</h1>
        <p class="page-header__meta">Correlated monitoring observations only. Correlation never mutates billing, provisioning or customer lifecycle state.</p>
    </div>
    <div class="page-header__aside">
        <span class="ui-status-badge ui-status-badge--{{ $incident->status }}">{{ $incident->status }}</span>
        <a class="button button--quiet button--sm" href="{{ route('monitoring.index') }}#outage-incidents">Back to console</a>
    </div>
</div>

<div class="ops-grid ops-grid--split">
    <section class="panel" aria-labelledby="lifecycle-heading">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">Lifecycle</p>
                <h2 class="panel__title" id="lifecycle-heading">Detected → acknowledged → resolved</h2>
            </div>
            <span class="panel__meta">{{ $incident->status }}</span>
        </div>
        <div class="panel__body">
            @include('monitoring._stages', ['incident' => $incident])
            <dl class="meta-list" style="margin-top: var(--cl-space-lg)">
                <div><dt>Router</dt><dd>{{ $incident->router?->name ?? '—' }}</dd></div>
                <div><dt>Detected</dt><dd class="mono-value">{{ $incident->detected_at?->format('Y-m-d H:i:s') ?? '—' }}</dd></div>
                <div><dt>Acknowledged</dt><dd class="mono-value">{{ $incident->acknowledged_at?->format('Y-m-d H:i:s') ?? '—' }}</dd></div>
                <div><dt>Resolved</dt><dd class="mono-value">{{ $incident->resolved_at?->format('Y-m-d H:i:s') ?? '—' }}</dd></div>
            </dl>
        </div>
    </section>

    <section class="panel" aria-labelledby="evidence-heading">
        <div class="panel__head">
            <div>
                <p class="panel__kicker">Evidence</p>
                <h2 class="panel__title" id="evidence-heading">Correlation evidence</h2>
            </div>
            <span class="panel__meta">{{ $incident->evidence_window_minutes }} min window</span>
        </div>
        <div class="panel__body">
            <dl class="meta-list">
                <div><dt>Correlated observations</dt><dd class="mono-value">{{ $incident->correlation_count }}</dd></div>
                <div><dt>Affected connections</dt><dd class="mono-value">{{ $incident->affectedConnections->count() }}</dd></div>
                <div><dt>Affected customers</dt><dd class="mono-value">{{ $incident->affectedConnections->pluck('customer_id')->unique()->count() }}</dd></div>
            </dl>
            @if ($incident->status === 'detected')
                <form method="post" action="{{ route('monitoring.incidents.acknowledge', $incident) }}" style="margin-top: var(--cl-space-md)">
                    @csrf
                    <button type="submit">Acknowledge incident</button>
                </form>
                <p class="console-note">Acknowledging records operator awareness only; it does not change network or billing state.</p>
            @else
                <p class="console-note">Acknowledged {{ $incident->acknowledged_at?->diffForHumans() ?? 'not yet acknowledged' }}.</p>
            @endif
        </div>
    </section>
</div>

<section class="panel" aria-labelledby="affected-heading" style="margin-top: var(--cl-space-md)">
    <div class="panel__head">
        <div>
            <p class="panel__kicker">Impact</p>
            <h2 class="panel__title" id="affected-heading">Affected customers and connections</h2>
        </div>
        <span class="panel__meta">{{ $incident->affectedConnections->count() }} connections</span>
    </div>
    <div class="panel__body panel__body--flush">
        <div class="table-scroll">
            <table>
                <thead>
                    <tr><th>Customer</th><th>Connection</th><th>Lifecycle</th><th>Notification</th><th>Detected message</th><th>Resolved message</th></tr>
                </thead>
                <tbody>
                    @forelse ($incident->affectedConnections as $connection)
                        @php($messages = $incident->messageLogs->where('customer_id', $connection->customer_id))
                        @php($notification = $messages->whereIn('template', ['outage_detected', 'outage_resolved'])->pluck('status')->unique()->implode(', ') ?: 'pending')
                        @php($detectedMessage = $messages->where('template', 'outage_detected')->pluck('status')->first() ?? 'pending')
                        @php($resolvedMessage = $messages->where('template', 'outage_resolved')->pluck('status')->first() ?? 'pending')
                        <tr>
                            <td>{{ $connection->customer?->name ?? '—' }}</td>
                            <td class="mono-value">{{ $connection->connection_code }}</td>
                            <td><span class="ui-status-badge ui-status-badge--{{ $connection->status }}">{{ $connection->status }}</span></td>
                            <td>{{ $notification }}</td>
                            <td>{{ $detectedMessage }}</td>
                            <td>{{ $resolvedMessage }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">No connections are correlated with this incident.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
@endsection

@extends('layouts.app')

@section('content')
<div class="page-header">
    <div>
        <p class="eyebrow"><span class="eyebrow__ord">03</span><span class="eyebrow__sep">·</span>Health history</p>
        <h1>{{ $subject->name ?? $subject->connection_code }}</h1>
        <p class="page-header__meta">{{ strtoupper($type) }} observation audit trail. Historical records are read-only and never rewritten by later checks.</p>
    </div>
    <div class="page-header__aside">
        <a class="button button--quiet button--sm" href="{{ route('monitoring.index') }}">Back to console</a>
    </div>
</div>

<section class="panel" aria-labelledby="history-ledger">
    <div class="panel__head">
        <div>
            <p class="panel__kicker">Read-only register</p>
            <h2 class="panel__title" id="history-ledger">Observations</h2>
        </div>
        <span class="panel__meta">{{ $observations->count() }} records</span>
    </div>
    <div class="panel__body panel__body--flush">
<div class="table-scroll">
    <table>
        <thead>
            <tr><th>Time</th><th>State</th><th>Reachable</th><th>Online</th><th>Latency</th><th>Packet loss</th><th>Provider</th></tr>
        </thead>
        <tbody>
            @forelse ($observations as $observation)
                <tr>
                    <td><time class="cell-time">{{ $observation->observed_at }}</time></td>
                    <td><span class="ui-status-badge ui-status-badge--{{ $observation->health_state }}">{{ $observation->health_state }}</span></td>
                    <td>{{ $observation->reachable === null ? '—' : ($observation->reachable ? 'yes' : 'no') }}</td>
                    <td>{{ $observation->online === null ? '—' : ($observation->online ? 'yes' : 'no') }}</td>
                    <td class="mono-value">{{ $observation->latency_ms !== null ? $observation->latency_ms.' ms' : '—' }}</td>
                    <td class="mono-value">{{ $observation->packet_loss_percent !== null ? $observation->packet_loss_percent.'%' : '—' }}</td>
                    <td>{{ $observation->provider }}</td>
                </tr>
            @empty
                <tr><td colspan="7"><p class="empty-state">No observations recorded for this subject yet.</p></td></tr>
            @endforelse
        </tbody>
    </table>
</div>
</div>
</section>
@endsection

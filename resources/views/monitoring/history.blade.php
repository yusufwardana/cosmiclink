@extends('layouts.app')

@section('content')
<h1>Health History</h1><p>Subject: {{ $subject->name ?? $subject->connection_code }} ({{ strtoupper($type) }}). Historical observations are read-only audit records.</p>
<table><tr><th>Time</th><th>State</th><th>Reachable</th><th>Online</th><th>Latency</th><th>Packet Loss</th><th>Provider</th></tr>@foreach($observations as $observation)<tr><td>{{ $observation->observed_at }}</td><td>{{ strtoupper($observation->health_state) }}</td><td>{{ $observation->reachable === null ? '—' : ($observation->reachable ? 'yes' : 'no') }}</td><td>{{ $observation->online === null ? '—' : ($observation->online ? 'yes' : 'no') }}</td><td>{{ $observation->latency_ms !== null ? $observation->latency_ms.' ms' : '—' }}</td><td>{{ $observation->packet_loss_percent !== null ? $observation->packet_loss_percent.'%' : '—' }}</td><td>{{ $observation->provider }}</td></tr>@endforeach</table>
<a href="{{ route('monitoring.index') }}">Back to Monitoring</a>
@endsection
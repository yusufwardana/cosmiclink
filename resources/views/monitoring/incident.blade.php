@extends('layouts.app')

@section('content')
<h1>Outage Incident</h1>
<p><strong>OUTAGE INTELLIGENCE</strong> — correlated monitoring observations only; no billing or network enforcement is performed.</p>
<p>Status: {{ strtoupper($incident->status) }}</p><p>Router: {{ $incident->router->name }}</p><p>Detected: {{ $incident->detected_at }}</p><p>Acknowledged: {{ $incident->acknowledged_at ?? '—' }}</p><p>Resolved: {{ $incident->resolved_at ?? '—' }}</p><p>Correlation evidence: {{ $incident->correlation_count }} connections within {{ $incident->evidence_window_minutes }} minutes.</p>
@if($incident->status === 'detected')<form method="post" action="{{ route('monitoring.incidents.acknowledge', $incident) }}">@csrf<button>Acknowledge Incident</button></form>@endif
<h2>Affected Customers and Connections</h2><table><tr><th>Customer</th><th>Connection</th><th>Lifecycle Status</th></tr>@foreach($incident->affectedConnections as $connection)<tr><td>{{ $connection->customer->name }}</td><td>{{ $connection->connection_code }}</td><td>{{ strtoupper($connection->status) }}</td></tr>@endforeach</table>
<a href="{{ route('monitoring.index') }}">Back to Monitoring</a>
@endsection
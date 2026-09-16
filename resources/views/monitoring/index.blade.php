@extends('layouts.app')

@section('content')
<h1>Monitoring</h1>
<p><strong>SIMULATION MODE</strong> — FakeMonitoringDriver records deterministic simulated health; no physical router is monitored.</p>
<form method="post" action="{{ route('monitoring.check') }}">@csrf<button>Run Monitoring Check</button></form>
<p>Routers: online {{ $routerHealthSummary->get('online', collect())->count() }}, degraded {{ $routerHealthSummary->get('degraded', collect())->count() }}, offline {{ $routerHealthSummary->get('offline', collect())->count() }}, unknown {{ $routerHealthSummary->get('unknown', collect())->count() }}.</p>
<p>Connections: online {{ $connectionHealthSummary->get('online', collect())->count() }}, offline {{ $connectionHealthSummary->get('offline', collect())->count() }}, unknown {{ $connectionHealthSummary->get('unknown', collect())->count() }}.</p>
@if(config('monitoring.simulation'))
<h2>NETWORK HEALTH SIMULATION</h2><p>Set a simulated provider state first, then run the normal observation pipeline.</p>
@endif
<h2>ROUTERS</h2>
<table><tr><th>Router</th><th>Health</th><th>Latency</th><th>Packet Loss</th><th>Last Checked</th><th>Action</th></tr>
@foreach($routers as $router) @php($health = $router->networkHealth)
<tr><td>{{ $router->name }}</td><td>{{ strtoupper($health?->health_state ?? 'unknown') }}</td><td>{{ $health?->latency_ms !== null ? $health->latency_ms.' ms' : '—' }}</td><td>{{ $health?->packet_loss_percent !== null ? $health->packet_loss_percent.'%' : '—' }}</td><td>{{ $health?->observed_at ?? '—' }}</td><td><form method="post" action="{{ route('monitoring.routers.observe', $router) }}">@csrf<button>Check Health</button></form>@if(config('monitoring.simulation')) @foreach(['online' => 'Set Online', 'degraded' => 'Set Degraded', 'offline' => 'Set Offline'] as $state => $label)<form method="post" action="{{ route('monitoring.routers.simulation', $router) }}">@csrf<input type="hidden" name="state" value="{{ $state }}"><button>{{ $label }}</button></form>@endforeach @endif <a href="{{ route('monitoring.history', ['type' => 'router', 'id' => $router->id]) }}">History</a></td></tr>
@endforeach</table>
<h2>CONNECTIONS</h2>
<table><tr><th>Connection</th><th>Customer</th><th>Router</th><th>Health</th><th>Lifecycle</th><th>Last Checked</th><th>Action</th></tr>
@foreach($connections as $connection) @php($health = $connection->networkHealth)
<tr><td>{{ $connection->connection_code }}</td><td>{{ $connection->customer->name }}</td><td>{{ $connection->router->name }}</td><td>{{ strtoupper($health?->health_state ?? 'unknown') }}</td><td>{{ strtoupper($connection->status) }}</td><td>{{ $health?->observed_at ?? '—' }}</td><td><form method="post" action="{{ route('monitoring.connections.observe', $connection) }}">@csrf<button>Check Health</button></form>@if(config('monitoring.simulation')) @foreach(['online' => 'Set Online', 'offline' => 'Set Offline', 'unknown' => 'Set Unknown'] as $state => $label)<form method="post" action="{{ route('monitoring.connections.simulation', $connection) }}">@csrf<input type="hidden" name="state" value="{{ $state }}"><button>{{ $label }}</button></form>@endforeach @endif <a href="{{ route('monitoring.history', ['type' => 'connection', 'id' => $connection->id]) }}">History</a></td></tr>
@endforeach</table>
@endsection
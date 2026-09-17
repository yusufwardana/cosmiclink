{{--
    Outage incident stage track — Detected → Acknowledged → Resolved.
    Shared by the Blade incident surfaces so the lifecycle reads identically everywhere.
--}}
@php
    $stageTrack = [
        ['label' => 'Detected', 'time' => $incident->detected_at, 'state' => $incident->status === 'detected' ? 'hot' : 'done'],
        ['label' => 'Acknowledged', 'time' => $incident->acknowledged_at, 'state' => $incident->acknowledged_at ? 'done' : ($incident->status === 'detected' ? '' : 'active')],
        ['label' => 'Resolved', 'time' => $incident->resolved_at, 'state' => $incident->resolved_at ? 'done' : ($incident->status === 'acknowledged' ? 'active' : '')],
    ];
@endphp
<ol class="stage-track" aria-label="Incident lifecycle">
    @foreach ($stageTrack as $stage)
        <li class="stage {{ $stage['state'] !== '' ? 'stage--'.$stage['state'] : '' }}">
            <span class="stage__dot" aria-hidden="true"></span>
            <span class="stage__label">{{ $stage['label'] }}</span>
            <time class="stage__time" @if($stage['time']) datetime="{{ $stage['time']->toIso8601String() }}" @endif>{{ $stage['time']?->format('M j, H:i') ?? '—' }}</time>
        </li>
    @endforeach
</ol>

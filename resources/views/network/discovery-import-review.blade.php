@extends('layouts.app')
@section('content')
<div class="page-header"><div><p class="eyebrow">[IMPORT] · Discovery</p><h1>Review / Confirm Customer Imports</h1><p class="page-header__meta">Only the checked READY candidates will be imported. Confirmation is atomic and does not perform RouterOS writes.</p></div></div>
<section class="panel">
    <div class="panel__head"><div><p class="panel__kicker">Candidate Summary</p><h2 class="panel__title">{{ $summary['TOTAL READY'] }} READY of {{ count($candidates) }} candidates</h2></div><span class="panel__meta">PREVIEW ONLY</span></div>
    <div class="panel__body">
        <div class="table-scroll" style="margin-bottom:20px"><table><thead><tr><th>STATIC IP READY</th><th>HOTSPOT READY</th><th>NEEDS VALIDATION</th><th>ALREADY ADOPTED</th><th>DUPLICATE</th><th>EXCLUDED</th><th>TOTAL READY</th></tr></thead><tbody><tr><td>{{ $summary['STATIC IP READY'] }}</td><td>{{ $summary['HOTSPOT READY'] }}</td><td>{{ $summary['NEEDS VALIDATION'] }}</td><td>{{ $summary['ALREADY ADOPTED'] }}</td><td>{{ $summary['DUPLICATE'] }}</td><td>{{ $summary['EXCLUDED'] }}</td><td><strong>{{ $summary['TOTAL READY'] }}</strong></td></tr></tbody></table></div>
        <form method="post" action="{{ route('network.discovery.import.confirm') }}">
            @csrf
            @foreach($candidates as $candidate)
                <div class="panel" style="margin-bottom:12px; opacity:{{ $candidate['state'] === 'READY' ? '1' : '.78' }}">
                    @if($candidate['state'] === 'READY')
                        <label style="display:block;margin-bottom:8px"><input type="checkbox" name="candidate_keys[]" value="{{ $candidate['key'] }}" @checked(in_array($candidate['key'], $selectedKeys, true))> Select READY candidate</label>
                        <label>Customer name
                            <input name="names[{{ $candidate['key'] }}]" value="{{ $candidate['default_name'] }}" maxlength="255">
                        </label>
                    @endif
                    <p class="panel__kicker">{{ strtoupper($candidate['access_mode']) }} · {{ $candidate['state'] }}</p>
                    <dl class="spec">
                        <dt>Candidate name</dt><dd>{{ $candidate['name'] }}</dd>
                        <dt>Access mode</dt><dd class="mono-value">{{ strtoupper($candidate['access_mode']) }}</dd>
                        <dt>Network mechanism</dt><dd class="mono-value">{{ strtoupper($candidate['network_mechanism']) }}</dd>
                        <dt>Stable network identity</dt><dd class="mono-value">{{ $candidate['network_identity'] }}</dd>
                        <dt>Router ID</dt><dd class="mono-value">{{ $candidate['router_id'] }}</dd>
                        <dt>Discovery evidence</dt><dd class="mono-value">{{ implode(', ', $candidate['discovery_evidence']) }}</dd>
                        @if($candidate['hotspot_validation'])<dt>Hotspot account validation</dt><dd class="mono-value">{{ json_encode($candidate['hotspot_validation']) }}</dd>@endif
                        <dt>Device/session evidence</dt><dd class="mono-value">{{ $candidate['device_session_evidence'] ? json_encode($candidate['device_session_evidence']) : '—' }}</dd>
                        @if($candidate['reason'])<dt>Classification reason</dt><dd>{{ $candidate['reason'] }}</dd>@endif
                    </dl>
                </div>
            @endforeach
            <p class="console-note">Canary confirmation: select only MAHLOR. Do not select any other READY candidate.</p>
            <button class="button--primary" type="submit">Confirm Import Selected</button>
            <a class="button--quiet" href="{{ route('network.discovery.index') }}#import-customers">Back</a>
        </form>
    </div>
</section>
@endsection
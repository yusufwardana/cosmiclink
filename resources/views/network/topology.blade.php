@extends('layouts.app')

@section('content')
<div class="page-header topology-page-header">
    <div>
        <p class="eyebrow"><span class="eyebrow__ord">07</span><span class="eyebrow__sep">//</span>Network</p>
        <h1>Network Topology</h1>
        <p class="page-header__meta">Interactive network relationship explorer</p>
    </div>
    <div class="page-header__aside">
        <span class="topbar__mode">READ-ONLY</span>
    </div>
</div>

<div id="network-topology-vue" data-base-url="{{ request()->getBaseUrl() }}">
    <div class="noc-loading">
        <p class="empty-state">Loading Network Topology…</p>
    </div>
</div>
@endsection
@extends('layouts.app')

@section('content')
<div id="network-gis-map-vue" data-base-url="{{ request()->getBaseUrl() }}" data-workspace="true">
    <div class="noc-loading"><p class="empty-state">Loading GIS Network Map…</p></div>
</div>
@endsection
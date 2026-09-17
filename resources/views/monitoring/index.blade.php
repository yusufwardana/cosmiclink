@extends('layouts.app')
@section('content')
<div id="monitoring-vue" data-base-url="{{ request()->getBaseUrl() }}" data-simulation="{{ config('monitoring.simulation') ? 'true' : 'false' }}"></div>

@endsection
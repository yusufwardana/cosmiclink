<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0d1017">
    <title>{{ $title ?? 'CosmicLink — ISP Operations, Automated' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    @auth
        <a class="skip-link" href="#main">Skip to operations content</a>
        <div id="cosmiclink-shell" data-base-url="{{ request()->getBaseUrl() }}" data-csrf-token="{{ csrf_token() }}" data-current-route="{{ request()->route()?->getName() }}" data-tenant="{{ auth()->user()->tenant?->name }}" data-operator="{{ auth()->user()->name }}" data-simulation="{{ config('network.simulation') || config('monitoring.simulation') ? 'true' : 'false' }}"></div>
        <div class="app-main"><main class="content-wrap" id="main">@include('partials.flash') @yield('content')</main></div>
    @else
        <main class="guest-main" id="main">@include('partials.flash') @yield('content')</main>
    @endauth
</body>
</html>

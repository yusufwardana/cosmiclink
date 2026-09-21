<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#08161b">
    <title>{{ $title ?? 'CosmicLink — ISP Operations, Automated' }}</title>
    <script>
        (() => {
            const stored = localStorage.getItem('cosmiclink-theme');
            document.documentElement.dataset.theme = stored === 'light' || stored === 'dark' ? stored : 'dark';
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="scroll-progress" data-scroll-progress role="progressbar" aria-label="Page reading progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
    @auth
        <a class="skip-link" href="#main">Skip to operations content</a>
        <div id="cosmiclink-shell" data-base-url="{{ request()->getBaseUrl() }}" data-csrf-token="{{ csrf_token() }}" data-current-route="{{ request()->route()?->getName() }}" data-tenant="{{ auth()->user()->tenant?->name }}" data-operator="{{ auth()->user()->name }}" data-simulation="{{ config('network.simulation') || config('monitoring.simulation') ? 'true' : 'false' }}"></div>
        <div class="app-main"><main class="content-wrap" id="main">@include('partials.flash') @yield('content')</main></div>
    @else
        <main class="guest-main" id="main">@include('partials.flash') @yield('content')</main>
    @endauth
</body>
</html>

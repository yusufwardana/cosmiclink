<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'CosmicLink' }}</title>
    <style>
        body { font-family: system-ui; margin: 2rem; max-width: 1100px; }
        nav { display: flex; gap: 1rem; margin-bottom: 2rem; }
        table { border-collapse: collapse; width: 100%; }
        td, th { border: 1px solid #ddd; padding: .5rem; }
        input, select, textarea { display: block; margin: .25rem 0 1rem; padding: .4rem; width: 100%; max-width: 420px; }
        .notice { padding: .75rem; background: #fff3cd; }
        .error { color: #b00; }
        button { padding: .4rem .8rem; }
    </style>
</head>
<body>
    @auth
        <nav>
            <a href="{{ route('dashboard') }}">Dashboard</a>
            <a href="{{ route('customers.index') }}">Customers</a>
            <a href="{{ route('packages.index') }}">Internet Packages</a>
            <a href="{{ route('routers.index') }}">Routers</a>
            <a href="{{ route('network.accounts.index') }}">Network Lab / Simulated PPPoE</a>
            <a href="{{ route('network.logs.index') }}">Operation Logs</a>
            <form method="post" action="{{ route('logout') }}">
                @csrf
                <button>Logout</button>
            </form>
        </nav>
    @endauth

    @if (session('status'))
        <div class="notice">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="error">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</body>
</html>
@extends('layouts.app')

@section('content')
<div class="auth-panel">
    <p class="eyebrow"><span class="eyebrow__ord">00</span><span class="eyebrow__sep">·</span>Operator access</p>
    <h1>CosmicLink</h1>
    <p class="page-header__meta">ISP operations, automated. Sign in with your operator account to reach the command center.</p>
    <form method="post" action="{{ route('login.store') }}">
        @csrf
        <label>Email<input name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email"></label>
        <label>Password<input name="password" type="password" required autocomplete="current-password"></label>
        <button type="submit" class="button--block">Sign in</button>
    </form>
</div>
@endsection

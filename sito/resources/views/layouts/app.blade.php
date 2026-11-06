{{-- Pagine essenziali del passo 2: l'aspetto arriva al passo 3. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'EAS8')</title>
</head>
<body>
<header>
    <a href="{{ route('shop.index') }}">EAS8</a>
    @auth
        · <a href="{{ route('account.index') }}">Account</a>
        <form method="post" action="{{ route('logout') }}" style="display:inline">@csrf <button>Logout</button></form>
    @else
        · <a href="{{ route('login') }}">Login</a>
    @endauth
</header>
<main>
    @if ($errors->any())
        <div role="alert">
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    @yield('content')
</main>
</body>
</html>

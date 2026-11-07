{{-- Pagine pubbliche e area personale: aspetto del prototipo (prototipo/), testi in inglese tradotti in lang/it.json. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>@hasSection('title')@yield('title') · @endif EAS8 Lisbon</title>
    <meta name="description" content="{{ __('EAS8 Lisbon, the congress of the European Aligner Society: 8–10 April 2027, Lisbon.') }}">
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <link rel="preload" href="{{ asset('fonts/inter/inter-latin.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('css/site.css') }}">
</head>
<body>
<a class="skip-link" href="#content">{{ __('Skip to content') }}</a>

<header class="site-header dark">
    <div class="wrap site-header__in">
        <a class="brand" href="{{ route('home') }}">
            <img src="{{ asset('images/eas-logo.png') }}" width="84" height="47" alt="European Aligner Society">
            <span>EAS8 Lisbon</span>
        </a>
        <nav class="nav" aria-label="{{ __('Main') }}">
            <a href="{{ route('program.index') }}" @if (request()->routeIs('program.*')) aria-current="page" @endif>{{ __('Programme') }}</a>
            <a href="{{ route('speakers.index') }}" @if (request()->routeIs('speakers.*')) aria-current="page" @endif>{{ __('Speakers') }}</a>
            <a href="{{ route('shop.index') }}" @if (request()->routeIs('shop.*')) aria-current="page" @endif>{{ __('Tickets') }}</a>
            <a href="{{ route('membership.show') }}" @if (request()->routeIs('membership.*')) aria-current="page" @endif>{{ __('Membership') }}</a>
            @auth
                <a href="{{ route('account.index') }}" @if (request()->routeIs('account.*')) aria-current="page" @endif>{{ __('Your account') }}</a>
            @else
                <a href="{{ route('login') }}" @if (request()->routeIs('login*')) aria-current="page" @endif>{{ __('Log in') }}</a>
            @endauth
            <span class="lang" role="group" aria-label="{{ __('Language') }}">
                <a href="{{ route('locale', 'en') }}" lang="en" hreflang="en" @if (app()->getLocale() === 'en') aria-current="true" @endif><span aria-hidden="true">EN</span><span class="sr-only">English</span></a>
                <a href="{{ route('locale', 'it') }}" lang="it" hreflang="it" @if (app()->getLocale() === 'it') aria-current="true" @endif><span aria-hidden="true">IT</span><span class="sr-only">Italiano</span></a>
            </span>
        </nav>
    </div>
</header>

<main id="content" tabindex="-1">
    @if ($errors->any())
        <div class="wrap" style="margin-top: var(--s-5)">
            <div class="avviso avviso--err" role="alert">
                <b>{{ __('Please check the form') }}</b>
                <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        </div>
    @endif
    @if (session('status'))
        <div class="wrap" style="margin-top: var(--s-5)">
            <div class="avviso avviso--ok" role="status">{{ session('status') }}</div>
        </div>
    @endif
    @yield('content')
</main>

<footer class="site-footer">
    <div class="wrap site-footer__in">
        <img src="{{ asset('images/eas-logo.png') }}" width="84" height="47" alt="">
        <p>EAS8 Lisbon · European Aligner Society · {{ __('8–10 April 2027') }}</p>
        @auth
            <form method="post" action="{{ route('logout') }}" class="inline-form">
                @csrf
                <button class="link-btn" style="color: var(--c-dark-ink)">{{ __('Log out') }}</button>
            </form>
        @endauth
    </div>
</footer>
</body>
</html>

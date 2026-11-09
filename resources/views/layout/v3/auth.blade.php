<!doctype html>
<html lang="{{ __('config.html_language') }}">
<head>
    {{-- ukrainian-ui --}}
    <script src="{{ asset('v1/js/ff/ukrainian-ui-cache.js') }}?v={{ substr(hash_file('sha256', public_path('v1/js/ff/ukrainian-ui-cache.js')), 0, 12) }}" data-revision="{{ \FireflyIII\Support\UkrainianUi::revision() }}" nonce="{{ $JS_NONCE ?? '' }}" integrity="sha384-{{ base64_encode(hash_file('sha384', public_path('v1/js/ff/ukrainian-ui-cache.js'), true)) }}" crossorigin="anonymous"></script>
    {{-- currency-display --}}
    <script src="{{ asset('v1/js/ff/currency-display.js') }}?v={{ substr(hash_file('sha256', public_path('v1/js/ff/currency-display.js')), 0, 12) }}" nonce="{{ $JS_NONCE ?? '' }}" integrity="sha384-{{ base64_encode(hash_file('sha384', public_path('v1/js/ff/currency-display.js'), true)) }}" crossorigin="anonymous"></script>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow, noarchive, noodp, NoImageIndex, noydir">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <!--
    (AUTH)
    If the base href URL begins with "http://" but you are sure it should start with "https://",
    please visit the following page: https://bit.ly/FF3-broken-base-href
    -->
    <base href="{{ route('index', null, true) }}/">
    <title>{{ __('firefly.login_page_title')  }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes"/>
    <meta name="theme-color" content="#007bff" media="(prefers-color-scheme: light)"/>
    <meta name="theme-color" content="#1a1a1a" media="(prefers-color-scheme: dark)"/>
    <meta name="color-scheme" content="light dark">
    @vite(['sass/app.scss'])
    <x-layout.fav-icons-clean />
    <script nonce="{{ $JS_NONCE }}">
        (() => {
            'use strict'
            document.documentElement.setAttribute('data-bs-theme', (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'))
        })()
    </script>
</head>
<body class="login-page bg-body-secondary">
<div class="login-box">
    <div class="login-logo">
        @if(true=== ($IS_DEMO_SITE ?? false))
            <img src="images/logo-session.png" width="68" height="100" alt="Logo" title="Logo"/><br>
            <a href="{{ route('index', null, true) }}"><strong>Firefly</strong> III</a>
        @endif
    </div>
    @yield('content')
</div>
@vite(['js/pages/blank.js'])
@yield('scripts')

</body>
</html>

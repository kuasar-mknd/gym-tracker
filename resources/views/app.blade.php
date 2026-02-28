<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport"
        content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1, interactive-widget=resizes-content">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta property="csp-nonce" content="{{ Vite::cspNonce() }}">
    <meta name="theme-color" content="#F8FAFF">
    <meta name="mobile-web-app-capable" content="yes">
    <link rel="apple-touch-icon" href="/logo.svg">
    <link rel="manifest" href="/build/manifest.webmanifest">

    <title inertia>{{ config('app.name', 'GymTracker') }}</title>

    <!-- Fonts: Archivo (Display), Space Grotesk (Body), Barlow Condensed (Accent) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Archivo:ital,wght@0,400;0,600;0,700;0,800;0,900;1,400;1,700;1,800;1,900&family=Space+Grotesk:wght@300;400;500;600;700&family=Barlow+Condensed:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">

    <!-- Material Symbols -->
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap"
        rel="stylesheet">

    <!-- Sentry Runtime Config -->
    <script nonce="{{ Vite::cspNonce() }}">
        window.SENTRY_CONFIG = {
            dsn: '{{ config('sentry.dsn') }}',
            environment: '{{ app()->environment() }}'
        };
    </script>

    <!-- Scripts -->
    @routes(nonce: Vite::cspNonce())
    @vite(['resources/js/main.js', "resources/js/Pages/{$page['component']}.vue"])
    @inertiaHead
</head>

<body class="font-sans antialiased bg-pearl-white text-text-main dark:bg-slate-900 dark:text-slate-100">
    @inertia
</body>

</html>

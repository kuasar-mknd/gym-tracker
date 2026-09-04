<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport"
        content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta property="csp-nonce" content="{{ Vite::cspNonce() }}">
    <meta name="theme-color" content="{{ \App\Support\Charte::jeton('surface-page') }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="apple-touch-icon" href="/logo.svg">
    <link rel="manifest" href="/manifest.webmanifest">

    <title inertia>{{ config('app.name', 'GymTracker') }}</title>

    {{-- Fonts are self-hosted and declared in resources/css/fonts.css, so they
         arrive with the bundle. They used to be four render-blocking requests
         to fonts.googleapis.com, which left the installed PWA with no fonts
         offline and sent every visitor's IP to a third party on page load. --}}

    {{-- Le DSN du navigateur arrive ici, a l'execution, et non par une variable
         de build : Vite substituerait `import.meta.env.VITE_*` au moment du
         `npm run build`, donc le DSN partirait dans l'image publiee. Le depot
         est public et l'image aussi — chaque installation enverrait ses erreurs
         au meme projet Sentry. Ici, chaque deploiement fournit le sien.

         Le bloc n'est rendu que s'il y a une valeur : une installation qui ne
         configure rien n'expose aucun objet global vide, et main.js n'initialise
         simplement pas Sentry. --}}
    @if (config('sentry.dsn_public'))
        <script nonce="{{ Vite::cspNonce() }}">
            window.SENTRY_CONFIG = {
                dsn: @json(config('sentry.dsn_public')),
                environment: @json(app()->environment())
            };
        </script>
    @endif

    {{--
        This is the only copy of the route table.

        HandleInertiaRequests also shared it as an Inertia prop, so every page
        carried the same 34 KB twice — 157.9 KB of HTML on /login against 102.9
        without. The prop was the one to go: @routes defines the global route()
        that page scripts call directly, and removing it throws
        "route is not defined" the moment a page renders. The prop also travelled
        again as JSON on every Inertia navigation, which the inline copy does not.
    --}}
    <!-- Scripts -->
    @routes(nonce: Vite::cspNonce())
    @vite(['resources/js/main.js', "resources/js/Pages/{$page['component']}.vue"])
    @inertiaHead
</head>

<body class="bg-surface-page text-text-main font-sans antialiased">
    @inertia
</body>

</html>

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport"
        content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Le nonce va dans l'attribut nonce : c'est lui que lit l'aide de
         préchargement de Vite (`.nonce`, puis getAttribute('nonce')), et lui
         que le navigateur masque au DOM. Dans `content`, rien ne le lisait,
         sauf un sélecteur CSS d'attribut (#1904). --}}
    <meta property="csp-nonce" nonce="{{ Vite::cspNonce() }}">
    <meta name="theme-color" content="{{ \App\Support\Charte::jeton('surface-page') }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    {{-- iOS ne lit pas un SVG en apple-touch-icon : sans PNG, l'écran
         d'accueil montrait une capture de la page (#1850). Il peint aussi en
         noir tout pixel transparent, d'où une icône opaque, sans marge. --}}
    <link rel="icon" href="/favicon.ico" sizes="48x48">
    <link rel="icon" href="/logo.svg" sizes="any" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon-180x180.png">
    <link rel="manifest" href="/manifest.webmanifest">

    <title inertia>{{ config('app.name', 'GymTracker') }}</title>

    {{-- Fonts are self-hosted and declared in resources/css/fonts.css, so they
         arrive with the bundle. They used to be four render-blocking requests
         to fonts.googleapis.com, which left the installed PWA with no fonts
         offline and sent every visitor's IP to a third party on page load. --}}

    {{--
        La seule copie de la table des routes.

        HandleInertiaRequests la partageait aussi en prop Inertia : chaque page
        portait deux fois les mêmes 34 Ko, et la prop repartait en JSON à
        chaque navigation Inertia. @routes n'écrit que la table (`const Ziggy`) :
        la fonction route() que les pages appellent vient du bundle, posée en
        globale par main.js (config/ziggy.php, #1969).
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

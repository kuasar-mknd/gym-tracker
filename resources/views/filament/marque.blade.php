{{--
    La marque du panneau : le logo de l'application, seul, comme dans
    l'en-tête de l'application (décision du propriétaire du 2026-10-15).

    Filament la pose dans `.fi-logo`, à la hauteur de `brandLogoHeight()`, et
    dans le lien vers l'accueil du panneau. Le nom du panneau est le texte de
    remplacement du logo : il nomme ce lien. Les styles sont en ligne : la
    feuille du panneau est celle de Filament, qui ne connaît pas les
    utilitaires de l'application, et sa CSP autorise les attributs de style.
--}}
<img src="{{ asset('logo.svg') }}" alt="{{ filament()->getBrandName() }}" width="32" height="32" style="height: 100%; width: auto;">

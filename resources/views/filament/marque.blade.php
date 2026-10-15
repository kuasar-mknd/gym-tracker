{{--
    La marque du panneau : le logo de l'application et son nom, comme dans
    l'en-tête de l'application.

    Filament la pose dans `.fi-logo`, à la hauteur de `brandLogoHeight()`, et
    dans le lien vers l'accueil du panneau. Le logo est décoratif (`alt=""`) :
    le nom écrit à côté nomme ce lien, et un `alt` qui le répéterait ferait
    annoncer « GymTracker GymTracker ». Les styles sont en ligne : la feuille
    du panneau est celle de Filament, qui ne connaît pas les utilitaires de
    l'application, et sa CSP autorise les attributs de style.
--}}
<span style="display: flex; align-items: center; gap: 0.5rem; height: 100%;">
    <img src="{{ asset('logo.svg') }}" alt="" width="32" height="32" style="height: 100%; width: auto;">
    <span>{{ filament()->getBrandName() }}</span>
</span>

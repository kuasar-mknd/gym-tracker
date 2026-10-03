---
paths:
  - 'bootstrap/app.php, app/Http/Middleware/**'
---

# Middleware

## Les en-têtes d'une réponse tiennent dans 4 Kio, sinon le proxy inverse rend un 502
Le proxy inverse de production lit tous les en-têtes d'une réponse dans un tampon de 4 Kio. Au-delà : un 502 et sa propre page d'erreur — jamais celle de l'application. `AddLinkHeadersForPreloadedAssets` a franchi la limite en septembre 2026 : son en-tête `Link` listait chaque morceau préchargé avec URL absolue et nonce (2 293 octets sur l'accueil, 4 355 au total). Seuls les chargements complets le portaient, pas les navigations Inertia : l'application marchait en naviguant et bloquait la PWA à l'ouverture, cookie « se souvenir de moi » aidant (#1902). Ne pas le remettre ; tout nouvel en-tête ou cookie se mesure contre `tests/Feature/EnTetesDeReponseTest.php`, qui tient un budget de 3 Kio par page complète et par réponse de connexion (trois cookies, la plus chargée). Seuls les en-têtes qui recopient l'URL demandée restent sans borne — le `Location` de `redirect()->intended()`, le `X-Inertia-Location` d'un 409 : il faut une URL de plus de 2 Kio pour les faire déborder, ce qu'aucune page de l'application ne produit.

## Nonce CSP : un script en ligne se signe dans son gabarit, jamais sur la réponse
Le nonce est tiré à chaque requête par `NonceCspParRequete`, en tête de la pile globale (#1904) ; tout le reste le lit par `Vite::cspNonce()`. Un paquet qui écrit des `<script>` nus (Filament, filament-exceptions, le lecteur de journaux) se signe à la compilation de ses gabarits, par le précompilateur `app/Support/Csp/Nonce/SigneLesScriptsEnLigneDesPaquets.php` : ajouter son dossier `vendor/` à la liste, jamais un middleware qui remplace `<script>` dans la réponse rendue — il signerait aussi le script qu'une donnée injectée y aurait glissé. `PulseNonceMiddleware` fait encore ainsi pour Pulse, coupé en production. Une vue compilée avant un changement du précompilateur garde l'ancienne version : `php artisan view:clear`. Une pile de routes hors du groupe `web` (le panneau) doit reprendre `ConditionalCspHeaders`, sans quoi elle répond sans CSP (#1920) ; `'unsafe-eval'` ne sort que sur le panneau et Horizon, qui compilent leurs gabarits à l'exécution (#1921).

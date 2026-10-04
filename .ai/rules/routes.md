---
paths:
  - routes/web.php
---

# Routes

## Aucun fichier demandé par le service worker ne passe par une route du groupe web
Le worker (`public/sw.js`, construit) et le manifeste (`public/manifest.webmanifest`, versionné) sont des fichiers statiques servis sans PHP. Ne jamais les remettre derrière une route du groupe `web` : `StartSession` et `PreventRequestForgery` posent un `Set-Cookie` de session sur chaque réponse, et une requête du precache partie sans cookie écrase celui de l'utilisateur ou du test Dusk suivant (cinq runs Dusk sur six rouges après #1698, corrigé par #1704 le 2026-09-04). Même règle pour tout fichier récupéré hors session (icônes, polices) : `public/` ou `public/build/`, jamais une route.

## Le rappel d'Apple arrive en POST inter-sites, sans cookie de session : il se protège par son nonce, pas par la session
Réglé le 2026-10-04 (#1911). Apple renvoie l'utilisateur par un formulaire posté depuis son site (`response_mode=form_post`) : ni jeton CSRF, ni `Sec-Fetch-Site: same-origin`, ni cookie de session (`SameSite=lax`). D'où trois choses qui vont ensemble. La route `POST /auth/apple/callback` est seule ouverte en POST (Google et GitHub rappellent en GET, un POST chez eux reste un 405) et seule exclue de PreventRequestForgery, par son chemin exact. Le pilote `apple` est `FournisseurApple` (`app/Support/ConnexionSociale/FournisseurApple.php`), le pilote du paquet réglé sans état et avec le nonce par cookie (`cookieNonce()`) au départ comme au retour : ne pas rendre l'état à la session, ni passer `SESSION_SAME_SITE` à `none` pour l'y faire survivre, ce qui relâcherait tous les cookies de l'application. Le cookie du nonce, déjà chiffré par le paquet, est retiré d'`EncryptCookies` : chiffré deux fois, la redirection vers Apple passait le budget d'en-têtes (`.ai/rules/middleware.md`). `tests/Feature/ConnexionAppleTest.php` joue le trajet entier avec `Tests\Support\AppleSimule` (clés tirées pour l'occasion, jamais une vraie) ; entre deux requêtes d'un même test, `nouvelleRequete()` fait oublier à Socialite ses pilotes, qui retiennent la requête qui les a créés, comme Octane le fait.

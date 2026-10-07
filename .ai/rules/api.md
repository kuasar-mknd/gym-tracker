---
paths:
  - 'routes/api.php, app/Http/Controllers/Api/**'
---

# Api

## L'API v1 ne sert que les sept écritures de la page de séance
Depuis #1673 (2026-09-03), `routes/api.php` n'expose que `sets` (store, update, destroy), `workout-lines` (store, destroy, set-order) et `workouts/{workout}/line-order`, authentifiés par la session Sanctum ET vérifiés CSRF (l'exemption `api/*` a été retirée). Leurs appelants passent tous par `SyncService`, qui écrit par `http` (`resources/js/Utils/http.js`) : ce module envoie `X-CSRF-TOKEN`, lu dans la balise `<meta name="csrf-token">` de `resources/views/app.blade.php`, et Laravel admet d'abord une requête qui porte `Sec-Fetch-Site: same-origin` (`PreventRequestForgery`). Seules les visites Inertia envoient `X-XSRF-TOKEN`, lu dans le cookie. Un 419 sur une route API se cherche donc du côté de la balise meta et de `Sec-Fetch-Site`, pas du cookie. N'ajoute pas de route API sans consommateur dans `resources/js` : `ZiggyExpositionTest` liste nommément les sept routes API autorisées, et toute nouvelle route doit y être ajoutée avec son appelant. Le futur client mobile (ADR 0001, amendé) repart d'un contrat explicite à jetons, pas de l'ancienne API REST. Pas de spec OpenAPI : `l5-swagger` est parti avec l'API.

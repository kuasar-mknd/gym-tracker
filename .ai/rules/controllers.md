---
paths:
  - 'app/Http/Controllers/**'
---

# Controllers

## Une écriture appelée en XHR reçoit 204 ou du JSON, jamais une redirection
Un contrôleur web appelé en XHR, par `http` (`resources/js/Utils/http.js`) ou `SyncService` (PATCH, PUT, DELETE), ne doit pas répondre par `Redirect::` : un navigateur qui suit un 302 garde la méthode pour tout sauf POST et rejoue par exemple `PATCH /profile/edit`, d'où un 405 et un message d'échec pour une écriture pourtant faite (vu en production le 2026-09-04, corrigé par #1707). Répondre `response()->noContent()` (ou du JSON) quand `$request->expectsJson()`, garder la redirection pour un formulaire classique, et couvrir le cas XHR par un test `patchJson(...)->assertNoContent()`.

## Une écriture qui rattache un appareil au compte revérifie la session sous verrou
Le middleware ne vérifie la session qu'à l'entrée de la requête : un mot de passe changé pendant la validation laissait la session fermée écrire après le retrait de ses abonnements push. `PushSubscriptionController::update` relit le compte sous `lockForUpdate()`, appelle `AuthentifieLaSessionDuCompte::fermerSiLaSessionNeTientPlus()`, puis écrit dans la même transaction ; toute écriture du même genre suit ce modèle (`.ai/rules/models.md`, « Un mot de passe changé retire les abonnements push du compte »).

## Un message flash s'écrit en français
Le layout affiche `success` et `error` en toast, tels quels, et un lecteur d'écran les lit avec la prononciation de la page. Trois contrôleurs les écrivaient en anglais (« Timer created successfully. ») (#1973). `LesMessagesFlashSontEnFrancaisTest` refuse un mot anglais de confirmation dans un message écrit en dur sous ces clefs.

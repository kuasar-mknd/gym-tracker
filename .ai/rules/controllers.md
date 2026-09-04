---
paths:
  - 'app/Http/Controllers/**'
---

# Controllers

## Une écriture appelée en XHR reçoit 204 ou du JSON, jamais une redirection
Un contrôleur web appelé par axios ou `SyncService` (PATCH, PUT, DELETE) ne doit pas répondre par `Redirect::` : un navigateur qui suit un 302 garde la méthode pour tout sauf POST et rejoue par exemple `PATCH /profile/edit`, d'où un 405 et un message d'échec pour une écriture pourtant faite (vu en production le 2026-09-04, corrigé par #1707). Répondre `response()->noContent()` (ou du JSON) quand `$request->expectsJson()`, garder la redirection pour un formulaire classique, et couvrir le cas XHR par un test `patchJson(...)->assertNoContent()`.

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

## Une demande d'invité ne dit pas si une adresse a un compte
Réglé le 2026-10-11 (#1951). « Mot de passe oublié » répondait par une erreur à une adresse inconnue, par un succès à une adresse connue, et autrement encore quand le courtier limitait la demande, ce qui n'arrive qu'à un compte existant ; la demande d'une adresse connue durait aussi plus longtemps. La requête ne cherche plus le compte : `SendPasswordResetLinkAction` met en file `EnvoyerLeLienDeReinitialisation` (chiffré, après la validation de la transaction, dans la langue de la demande), qui appelle le courtier et garde son statut pour lui, et la page répond toujours `passwords.sent`, écrit pour les deux cas. Le limiteur de la route reste celui de toutes les adresses. La réinitialisation refuse tout échec par `passwords.token`, jamais par `passwords.user`. Toute nouvelle entrée qui envoie un lien (API, panneau) passe par ce job, sans lire le statut du courtier. `MotDePasseOublieMemeReponseTest` (tests/Feature/Security) compare les réponses entières, en-têtes, cookies, session et page suivante compris, et refuse un appel au courtier hors du job. L'inscription et le changement d'adresse du profil disent encore, par leur règle `unique`, qu'une adresse est prise : la décision est à prendre à part.

---
paths:
  - 'app/Http/Requests/**'
---
# Requests

## L'autorisation d'une ressource vit au contrôleur, pas dans authorize()
Une requête de validation ne vérifie que la connexion (`$this->user() !== null`) ; la policy est appelée par le contrôleur (`$this->authorize(...)`), et le refus sur une ressource d'autrui, validation comprise, est rendu en 404 par `bootstrap/app.php`.
Une seule exception, mesurée par `WebResourceDisclosureContractTest` (canal « travail » : nombre de requêtes SQL) : quand une règle `exists` ferait interroger la base avant le refus, l'`authorize()` de la requête doit refuser avant la validation (`GoalStoreRequest` pour `goals.update`).
Avant de déplacer une autorisation, lancer ce contrat et `ResourceDisclosureContractTest` (#1676, 2026-09-04).

## Un attribut d'accès ne change qu'avec le mot de passe actuel, et le compteur d'essais ne se vide que sur un mot de passe accepté
Le mot de passe (`UpdatePasswordRequest`), la suppression du compte (`DeleteUserRequest`) et l'adresse (`ProfileUpdateRequest`, seulement quand elle change) exigent `current_password` et comptent les essais manqués sous une clé par compte (2026-10-04). L'adresse compte les siens sous la clé du mot de passe (`update-password-{id}`, celle de `UpdatePasswordRequest::throttleKey()`) : les deux formulaires évaluent le même mot de passe, et une clé propre rendrait cinq essais par minute à une session ouverte par quelqu'un d'autre, une fois ceux du mot de passe épuisés. Une nouvelle requête qui évalue le mot de passe actuel reprend cette clé plutôt que d'en ouvrir une ; la suppression du compte garde encore la sienne (`delete-account-{id}`), plus ancienne que cette règle. Le compteur ne se vide que lorsque le mot de passe a été vérifié : `ProfileUpdateRequest` laisse passer le nom seul sans mot de passe, et un `passedValidation()` qui viderait le compteur sur ce chemin offrirait une remise à zéro entre deux essais. Un compte relié à un fournisseur peut n'avoir jamais connu son mot de passe (`ResolveSocialUserAction` en tire un au hasard) : le message d'erreur lui donne le chemin, « Mot de passe oublié ? » après déconnexion, la route n'étant ouverte qu'aux invités. `tests/Feature/Security/ChangementDAdresseTest.php` tient ces points.

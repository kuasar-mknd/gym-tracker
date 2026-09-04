---
paths:
  - 'app/Http/Requests/**'
---
# Requests

## L'autorisation d'une ressource vit au contrôleur, pas dans authorize()
Une requête de validation ne vérifie que la connexion (`$this->user() !== null`) ; la policy est appelée par le contrôleur (`$this->authorize(...)`), et le refus sur une ressource d'autrui, validation comprise, est rendu en 404 par `bootstrap/app.php`.
Une seule exception, mesurée par `WebResourceDisclosureContractTest` (canal « travail » : nombre de requêtes SQL) : quand une règle `exists` ferait interroger la base avant le refus, l'`authorize()` de la requête doit refuser avant la validation (`GoalStoreRequest` pour `goals.update`).
Avant de déplacer une autorisation, lancer ce contrat et `ResourceDisclosureContractTest` (#1676, 2026-09-04).

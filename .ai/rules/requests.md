---
paths:
  - 'app/Http/Requests/**'
---
# Requests

## L'autorisation d'une ressource vit au contrôleur, pas dans authorize()
Une requête de validation ne vérifie que la connexion (`$this->user() !== null`) ; la policy est appelée par le contrôleur (`$this->authorize(...)`), et le refus sur une ressource d'autrui, validation comprise, est rendu en 404 par `bootstrap/app.php`.
Une seule exception, mesurée par `WebResourceDisclosureContractTest` (canal « travail » : nombre de requêtes SQL) : quand une règle `exists` ferait interroger la base avant le refus, l'`authorize()` de la requête doit refuser avant la validation (`GoalStoreRequest` pour `goals.update`).
Avant de déplacer une autorisation, lancer ce contrat et `ResourceDisclosureContractTest` (#1676, 2026-09-04).

## Une nouvelle adresse de compte s'écrit en ASCII imprimable
`users.email` et son index unique sont en `utf8mb4_unicode_ci`, qui confond une adresse accentuée, un « ß », une lettre pleine chasse avec l'adresse ASCII voisine : un compte ouvert sur l'une occupait l'autre, sans en détenir la boîte, et le lien de réinitialisation demandé pour l'adresse ASCII partait à l'adresse du compte. L'inscription (`RegisterRequest`) et le profil (`ProfileUpdateRequest`) passent par `App\Rules\AdresseEnAsciiImprimable`, avec `lowercase` : entre deux adresses en ASCII imprimable et en minuscules, la collation ne confond rien. Le profil lui donne l'adresse actuelle du compte, admise telle quelle, pour qu'un compte ancien enregistre son nom sans changer d'adresse. Toute nouvelle voie qui écrit une adresse de compte suit la même règle. Témoin : `AdresseEnAsciiImprimableTest`.

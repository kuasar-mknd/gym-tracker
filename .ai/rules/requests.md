---
paths:
  - 'app/Http/Requests/**'
---
# Requests

## L'autorisation d'une ressource vit au contrôleur, pas dans authorize()
Une requête de validation ne vérifie que la connexion (`$this->user() !== null`) ; la policy est appelée par le contrôleur (`$this->authorize(...)`), et le refus sur une ressource d'autrui, validation comprise, est rendu en 404 par `bootstrap/app.php`.
Une seule exception, mesurée par `WebResourceDisclosureContractTest` (canal « travail » : nombre de requêtes SQL) : quand une règle `exists` ferait interroger la base avant le refus, l'`authorize()` de la requête doit refuser avant la validation (`GoalStoreRequest` pour `goals.update`).
Avant de déplacer une autorisation, lancer ce contrat et `ResourceDisclosureContractTest` (#1676, 2026-09-04).

## Une règle numérique porte ses deux bornes, sous la capacité de sa colonne
Un `integer` ou un `numeric` qui n'a qu'un `min:` laisse passer un nombre que la colonne refuse : MySQL le rejette, la requête finit en 500 au lieu d'un 422 sur le champ, et chaque 500 écrit une ligne d'exception. Chaque règle numérique de `app/Http/Requests` porte donc une borne basse et une borne haute (`min:` et `max:`, `between:`, `size:`, ou une liste `in:` fermée), tirée d'une constante métier du modèle (`Set::POIDS_MAX_KG`, `IntervalTimer::TOURS_MAX`, `Goal::VALEUR_MAX`…) dont le docbloc dit pourquoi cette valeur. `LesReglesNumeriquesSontBorneesTest` (tests/Feature/Conventions) refuse une règle sans l'une des deux, hors des identifiants listés dans ses exceptions avec leur raison ; `LesBornesDesRequetesTiennentDansLaBaseTest` confronte ensuite les bornes des champs qu'il liste à la capacité de leur colonne, lue dans la base de test (#1986) : un champ qui écrit en base y entre. Une valeur recopiée ailleurs prend les bornes de sa destination : les séries d'un modèle de séance, qui deviennent celles d'une séance, prennent celles d'une série par `BorneLesSeriesDuGabarit`, partagé par la création et la modification. Les règles se lisent par `Tests\Support\ReglesDesRequetes`, telles que Laravel les applique.

---
paths:
  - '**/*.md'
---

# General

## Un chemin ou une version cités dans un document sont vérifiés par un test
`DocumentationSansDeriveTest` (tests/Feature/Conventions, #1671, 2026-09-03) lit les `*.md` de la racine, de `docs/` et de `.ai/rules/`. Tout chemin entre accents graves contenant un `/` dont le premier segment existe à la racine (`app/…`, `tests/…`, `docs/…`) doit exister ; toute cible de lien relative aussi. Toute mention « Laravel 13 », « Inertia 3 », « Vue 3 », « Tailwind 4 », « Filament 5 », « Pest 4 », « PHP 8.5 »… doit correspondre au majeur des manifestes (`composer.lock`, `package.json`, `PHP_VERSION`). Le bloc `<laravel-boost-guidelines>` de `CLAUDE.md` est exclu (généré par Boost), `CHANGELOG.md` aussi (il cite des fichiers disparus par nature). Pour citer un fichier sans que le test le vérifie, ne mets pas le chemin entre accents graves. Les quatre documents de mars (la feuille de route, l'analyse de restructuration, le plan de performance et l'enquête Dusk, tous sous docs/) ont été supprimés, pas archivés : ne pas les recréer, le journal des modifications et les issues GitHub tiennent lieu de feuille de route.

## Le code s'écrit en français

Commentaires, noms de méthodes, de propriétés et de variables : en français, comme les commits, les PR, les tests et la charte. La règle a été tranchée le 2026-09-07 (#1810) parce que chaque contributeur choisissait au hasard, et qu'une recherche par mot-clé ratait la moitié des occurrences.

Trois exceptions, parce qu'elles ne sont pas à nous :

- **Ce que le framework nomme** : `handle`, `boot`, `rules`, `authorize`, `up`, `down`, `casts`, `render`, les accesseurs d'attribut, les méthodes de test que Pest appelle.
- **Le schéma** : noms de tables, de colonnes, de relations Eloquent, et les clefs de charge utile d'une API. Les renommer casserait la base ou le contrat.
- **Les noms de classes** : ils portent des mots du domaine Laravel (`Controller`, `Policy`, `Request`, `Job`) et se lisent dans les traces d'erreur.

Une garde (`tests/Feature/Conventions/LaLangueDuCodeTest.php`) refuse un nouveau commentaire anglais dans `app/`.

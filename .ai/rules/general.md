---
paths:
  - '**/*.md'
---

# General

## Un chemin ou une version cités dans un document sont vérifiés par un test
`DocumentationSansDeriveTest` (tests/Feature/Conventions, #1671, 2026-09-03) lit les `*.md` de la racine, de `docs/` et de `.ai/rules/`. Tout chemin entre accents graves contenant un `/` dont le premier segment existe à la racine (`app/…`, `tests/…`, `docs/…`) doit exister ; toute cible de lien relative aussi. Toute mention « Laravel 13 », « Inertia 3 », « Vue 3 », « Tailwind 4 », « Filament 5 », « Pest 4 », « PHP 8.5 »… doit correspondre au majeur des manifestes (`composer.lock`, `package.json`, `PHP_VERSION`). Le bloc `<laravel-boost-guidelines>` de `CLAUDE.md` est exclu (généré par Boost), `CHANGELOG.md` aussi (il cite des fichiers disparus par nature). Pour citer un fichier sans que le test le vérifie, ne mets pas le chemin entre accents graves. Les quatre documents de mars (la feuille de route, l'analyse de restructuration, le plan de performance et l'enquête Dusk, tous sous docs/) ont été supprimés, pas archivés : ne pas les recréer, le journal des modifications et les issues GitHub tiennent lieu de feuille de route.

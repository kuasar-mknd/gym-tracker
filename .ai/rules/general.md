---
paths:
  - '**/*.md'
---

# General

## Un chemin ou une version cités dans un document sont vérifiés par un test
`DocumentationSansDeriveTest` (tests/Feature/Conventions, #1671, 2026-09-03) lit les `*.md` de la racine, de `docs/` et de `.ai/rules/`. Tout chemin entre accents graves contenant un `/` dont le premier segment existe à la racine (`app/…`, `tests/…`, `docs/…`) doit exister ; toute cible de lien relative aussi. Toute mention « Laravel 13 », « Inertia 3 », « Vue 3 », « Tailwind 4 », « Filament 5 », « Pest 5 », « PHP 8.5 »… doit correspondre au majeur des manifestes (`composer.lock`, `package.json`, `PHP_VERSION`). Ni un document, ni `eslint.config.js`, ni le code PHP de `app/`, `bootstrap/`, `config/` et `routes/` ne nomment un client HTTP que `package.json` n'installe plus (#1993) : le client est `http` (`resources/js/Utils/http.js`). Le bloc `<laravel-boost-guidelines>` de `CLAUDE.md` est exclu (généré par Boost), `CHANGELOG.md` aussi (il cite des fichiers disparus par nature). Pour citer un fichier sans que le test le vérifie, ne mets pas le chemin entre accents graves. Les quatre documents de mars (la feuille de route, l'analyse de restructuration, le plan de performance et l'enquête Dusk, tous sous docs/) ont été supprimés, pas archivés : ne pas les recréer, le journal des modifications et les issues GitHub tiennent lieu de feuille de route.

## Le code s'écrit en français

Commentaires, noms de méthodes, de propriétés et de variables : en français, comme les commits, les PR, les tests et la charte. La règle a été tranchée le 2026-09-07 (#1810) parce que chaque contributeur choisissait au hasard, et qu'une recherche par mot-clé ratait la moitié des occurrences.

Trois exceptions, parce qu'elles ne sont pas à nous :

- **Ce que le framework nomme** : `handle`, `boot`, `rules`, `authorize`, `up`, `down`, `casts`, `render`, les accesseurs d'attribut, les méthodes de test que Pest appelle.
- **Le schéma** : noms de tables, de colonnes, de relations Eloquent, et les clefs de charge utile d'une API. Les renommer casserait la base ou le contrat. Un **paramètre de méthode lié à un segment de route** en fait partie : Laravel apparie `{workout_line}` à `$workoutLine`, et le renommer injecte un modèle vide — la politique refuse alors en 403, sans que rien ne dise pourquoi.
- **Les noms de classes** : ils portent des mots du domaine Laravel (`Controller`, `Policy`, `Request`, `Job`) et se lisent dans les traces d'erreur.

Une garde (`tests/Feature/Conventions/LaLangueDuCodeTest.php`) refuse un nouveau commentaire anglais dans `app/`.

## Un problème trouvé en chemin s'ouvre en issue, pas seulement dans la conversation
Décision du propriétaire du dépôt (2026-10-02) : ne pas hésiter à ouvrir des issues. Un défaut repéré hors du sujet de la tâche — faille, bogue, dette, configuration incohérente, comportement de production douteux — devient une issue GitHub plutôt qu'une remarque perdue dans une conversation ou un commentaire de PR. Elle porte la preuve (fichier:ligne, mesure ou reproduction), l'impact, le correctif proposé et le test qui le prouverait, avec un titre au format des autres (`type(portée): constat`, en français) et un label existant (`security`, `ci`, `infra`, `frontend`…). Ce qui relève de la tâche en cours se corrige dans la PR ; seul ce qui l'élargirait part en issue. Exemples : #1904 (nonce CSP figé sous Octane), #1905 (labels de Dependabot absents).

## L'infrastructure de production ne se nomme jamais dans le dépôt
Décision du propriétaire du dépôt (2026-10-02), pour raison de sécurité : le dépôt est public, et nommer ce qui fait tourner la production dit à un attaquant quelles failles essayer. Ni le matériel, ni son système, ni l'outil qui gère la pile de conteneurs, ni le proxy inverse par son nom, ni le réseau privé, ni une adresse, un nom d'hôte ou un chemin de la machine ne s'écrivent — dans le code, les commentaires, les tests, la documentation, le CHANGELOG, les règles de `.ai/rules`, ni dans les messages de commit, les PR et les issues. On décrit la production par ce qu'elle fait : « le proxy inverse », « la pile », « le serveur de production », « le disque de production », « le dossier de l'hôte ». Les mesures restent (310 ms par écriture synchronisée, tampon d'en-têtes de 4 Kio) ; le nom de ce qui les produit, non. Une adresse d'exemple se prend dans les plages de documentation (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24, 2001:db8::/32), un nom d'hôte d'exemple sous example.org. `LInfrastructureNeSeNommePasTest` (tests/Feature/Conventions) lit chaque fichier suivi et refuse les noms interdits ; il n'en contient que les empreintes SHA-256, et son message d'échec ne cite que le fichier et la ligne, parce que les journaux de la CI d'un dépôt public sont publics.

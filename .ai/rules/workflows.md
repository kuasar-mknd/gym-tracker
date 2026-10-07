---
paths:
  - '.github/workflows/**'
---

# Workflows

## Doctor et Checkpoint dans le job audit : ce qui est exclu, et pourquoi
`php artisan doctor` tourne sur une `.env` copiée de `.env.example` avec les groupes base, cache, files, session, stockage et planificateur exclus (le job n'a pas ces services) et l'avis Composer exclu (déjà rendu par `composer audit --ignore-unreachable`). `checkpoint:scan` tourne avec `--skip` des deux audits de CVE (tenus par `composer audit` et OSV), de l'outillage de poste et de la fraîcheur des paquets ; la fraîcheur tourne à part en `continue-on-error`, parce qu'une montée Dependabot fusionnée le jour de sa publication la déclencherait trois jours de suite. Les noms de `--only`/`--skip` sont les libellés affichés (« Package Freshness (Supply Chain) »), pas les classes. Une suppression va dans `config/checkpoint.php` avec son pourquoi ; `exclude_paths` ne s'applique pas aux vues Blade. Un téléchargement de ChromeDriver se retente trois fois (#1752).

## Le job `demarrage` lance l'image avant `merge`
Le premier `migrate` d'une base neuve passe par le client mysql de l'image, et rien avant la production ne le voyait (#1767). `demarrage` télécharge le digest amd64 du job `build`, lance l'image sans changer sa commande ni son entrypoint contre une base et un cache de service aux images de la production (celles de `docker-compose.prod.yml`, que `LImageDemarreSurUneBaseNeuveTest` compare : il tournait en Redis 7 quand la production était en Redis 8), joints par `host.docker.internal` (`--add-host … host-gateway`), et attend `/up`. `merge` l'exige : sans réponse, ni `latest`, ni `sha`, ni tag de version. Comme `build`, il ne tourne que sur main et sur les tags, jamais sur une PR : la première exécution d'un changement s'observe après la fusion.

## Le robot d'inactivité ne touche qu'aux PR
Les issues tiennent lieu de feuille de route : `stale.yml` ne marque ni ne ferme aucune issue (`days-before-issue-stale` et `days-before-issue-close` à -1, aucun réglage commun `days-before-stale`/`days-before-close`), et le délai annoncé par le message des PR est celui de `days-before-pr-close`. `LeRobotDInactiviteEpargneLesIssuesTest` le tient (#1987).

## Une action se référence par une version, jamais par une branche
Le contrôle `secrets` tournait sur `trufflesecurity/trufflehog@main` et l'image `latest` : son verdict changeait sans diff, et Dependabot ne monte pas une branche (#1990). Chaque `uses:` porte une étiquette de version (`@v7`, `@v3.97.9`) ou un commit complet suivi de sa version en commentaire (`@<40 caractères> # v3.97.9`), que Dependabot sait monter. L'image de TruffleHog se choisit par l'entrée `version` de l'action, que Dependabot ne lit pas : elle se monte à la main dans la PR Dependabot de l'action, qui reste rouge tant que les deux diffèrent. Les images lancées par `docker run` ou `docker container run` (Semgrep, OSV, actionlint) ne sont surveillées par aucun outil : elles portent une version exacte (`1.179.0`, `v2.6.0`, jamais `latest`, une image sans étiquette ni une étiquette flottante comme `v2`, `3.22`, `edge` ou `8-alpine`) ou un digest, et se montent à la main ; une image référencée par `uses: docker://…` suit la même règle, et l'image du job `demarrage` est le digest que `build` vient de pousser. `LesActionsDeLaCiSontFigeesTest` (tests/Feature/Conventions) tient ces trois points, en lisant l'image de chaque `docker run` des étapes comme le shell (options sautées, variable du script résolue), et l'accord entre l'image OSV de la CI et celle que cite `.ai/rules/dependencies.md`.

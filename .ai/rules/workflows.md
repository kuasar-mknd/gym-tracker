---
paths:
  - '.github/workflows/**'
---

# Workflows

## Doctor et Checkpoint dans le job audit : ce qui est exclu, et pourquoi
`php artisan doctor` tourne sur une `.env` copiée de `.env.example` avec les groupes base, cache, files, session, stockage et planificateur exclus (le job n'a pas ces services) et l'avis Composer exclu (déjà rendu par `composer audit --ignore-unreachable`). `checkpoint:scan` tourne avec `--skip` des deux audits de CVE (tenus par `composer audit` et OSV), de l'outillage de poste et de la fraîcheur des paquets ; la fraîcheur tourne à part en `continue-on-error`, parce qu'une montée Dependabot fusionnée le jour de sa publication la déclencherait trois jours de suite. Les noms de `--only`/`--skip` sont les libellés affichés (« Package Freshness (Supply Chain) »), pas les classes. Une suppression va dans `config/checkpoint.php` avec son pourquoi ; `exclude_paths` ne s'applique pas aux vues Blade. Un téléchargement de ChromeDriver se retente trois fois (#1752).

## Le job `demarrage` lance l'image avant `merge`
Le premier `migrate` d'une base neuve passe par le client mysql de l'image, et rien avant la production ne le voyait (#1767). `demarrage` télécharge le digest amd64 du job `build`, lance l'image sans changer sa commande ni son entrypoint contre un MySQL 8.4 et un Redis de service joints par `host.docker.internal` (`--add-host … host-gateway`), et attend `/up`. `merge` l'exige : sans réponse, ni `latest`, ni `sha`, ni tag de version. Comme `build`, il ne tourne que sur main et sur les tags, jamais sur une PR : la première exécution d'un changement s'observe après la fusion.

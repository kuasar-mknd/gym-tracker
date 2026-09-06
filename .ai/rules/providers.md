---
paths:
  - 'app/Providers/**, routes/console.php'
---

# Providers

## Santé et moniteur des tâches : ce qui écrit en base tourne rarement, ce qui tourne souvent reste hors base
Chaque écriture SQL coûte entre 350 ms et 1,7 s sur le NAS (#1668). Les résultats de laravel-health vivent donc dans le cache (`CacheHealthResultStore`, magasin de l'application), pas en base ; `health:check` toutes les cinq minutes, les deux battements chaque minute. Les trois tâches de santé portent `->doNotMonitor()` pour le moniteur des tâches (trois lignes par passage, 1 728 passages par jour) : `ProductionRunsWhatItSchedulesTest` les exempte nommément, parce que `ScheduleCheck`, `QueueCheck` et la page de santé disent eux-mêmes quand ils manquent ; toute autre tâche reste sous le moniteur local, qui remplace les moniteurs Sentry retirés le 06/09/2026. `doNotMonitor()` est une macro que PHPStan ne type pas : on l'enchaîne en dernier avec `@phpstan-ignore method.nonObject`. `BackupsCheck::onDisk()` résout le disque au démarrage et fige sa racine avant qu'un test ne la déplace : passer par `locatedAt()` avec le chemin du disque. Une date calculée dans un provider (`now()->subHours(26)`) est prise au démarrage du processus : juste pour `health:check` (lancé à neuf), faux pour un worker Octane. Après un déploiement, l'image relit le planning (`schedule-monitor:sync` dans `entrypoint.sh`). `TachesPlanifieesCheck` (`app/Support/Sante`) lit ce moniteur : une tâche échouée met la santé au rouge, une tâche en retard à l'orange. Les courriels de santé ne partent que si `HEALTH_TO_ADDRESS` est posée, et seulement pour un rouge (`only_on_failure`) : un orange qui dure écrirait chaque heure.

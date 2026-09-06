---
paths:
  - 'app/Providers/**, routes/console.php'
---

# Providers

## Santé et moniteur des tâches : ce qui écrit en base tourne rarement, ce qui tourne souvent reste hors base
Chaque écriture SQL coûte entre 350 ms et 1,7 s sur le NAS (#1668). Les résultats de laravel-health vivent donc dans le cache (`CacheHealthResultStore`, magasin de l'application), pas en base ; `health:check` toutes les cinq minutes, les deux battements chaque minute. Les trois tâches de santé portent `->doNotMonitor()` pour le moniteur des tâches (trois lignes par passage, 1 728 passages par jour) et les battements n'ont pas de `sentryMonitor()` : `ProductionRunsWhatItSchedulesTest` les exempte nommément, parce que `ScheduleCheck` et `QueueCheck` disent eux-mêmes quand ils manquent. `sentryMonitor()` et `doNotMonitor()` sont des macros que PHPStan ne type pas : on les enchaîne en dernier, et la seconde porte `@phpstan-ignore method.nonObject`. `BackupsCheck::onDisk()` résout le disque au démarrage et fige sa racine avant qu'un test ne la déplace : passer par `locatedAt()` avec le chemin du disque. Une date calculée dans un provider (`now()->subHours(26)`) est prise au démarrage du processus : juste pour `health:check` (lancé à neuf), faux pour un worker Octane. Après un déploiement, l'image relit le planning (`schedule-monitor:sync` dans `entrypoint.sh`).

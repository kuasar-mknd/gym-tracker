---
paths:
  - 'app/Jobs/**'
---

# Jobs

## Un job que rien n'envoie : la suite échoue
Le détecteur de code mort de PHPStan tient pour utilisés le constructeur et `handle()` de tout job `ShouldQueue` : c'est ainsi que Laravel les appelle, et un job jamais envoyé passe donc l'analyse (`RecalculateUserStats` a vécu ainsi, #1991). `LesJobsEtLesMiddlewaresOntUnAppelantTest` exige que chaque job de `app/Jobs` soit envoyé ailleurs que dans son propre fichier, dans `app/`, `routes/` ou `bootstrap/`, sous l'une de ces formes : `Job::dispatch…()` ou `Job::withChain()` ; ou `new Job(…)`, `Job::class` ou son nom complet en chaîne passés directement, tableau compris, à `dispatch()`, `dispatch_sync()`, `Bus::chain()`, `Bus::batch()`, `Bus::dispatch…()`, `Schedule::job()`, `->job()` ou `->dispatch…()`. Un `new Job` qui n'est passé à aucun de ces appels ne compte pas : rangé dans une variable, passé au constructeur d'un autre objet, construit dans une fonction anonyme ou fléchée. Écrire donc l'envoi là où le job est construit. Un envoi fait seulement depuis un test ne compte pas non plus. Un job qui doit rester sans appelant s'inscrit dans `appelantsExceptions()` avec sa raison.

---
paths:
  - 'app/Actions/**'
  - 'app/Services/**'
  - 'app/Policies/**'
---

# Code muté la nuit

`App\Actions`, `App\Services` et `App\Policies` sont les espaces de noms que la passe de mutation nocturne mute, part par part (`.github/workflows/mutation.yml`). Une écriture qui ne coûte rien à l'exécution peut y coûter une part de la nuit.

## Une boucle à compteur donne un mutant qui ne finit jamais
La passe de mutation remplace `$i++` par `$i--`, ou l'inverse : `for ($i = 0; $i < 7; $i++)` ne s'arrête plus, et le mutant tient un processus de la nuit jusqu'au délai que Pest accorde à chaque mutant (la passe de référence + 20 %), ou jusqu'à épuiser la mémoire. Aucun test ne peut le tuer vite : la boucle ne rend jamais la main. Parcourir ce qui est borné par nature — `range()`, une collection, les jours d'une période (`$debut->daysUntil($fin)->toArray()`, typé, là où l'itération directe d'une `CarbonPeriod` est `mixed` pour PHPStan) — plutôt que compter. Vu sur la tendance hebdomadaire du volume, le mutant le plus long de la part `App\Services` : 336 s en local le 07/10 (#2004) ; puis sur quatre parcours de jours d'`App\Actions` (hydratation, compléments, statistiques et semaine des habitudes) : de 60 à 177 s chacun en local le 08/10, près de 580 s cumulées (#2017).

Les n derniers jours se comptent à rebours depuis un seul instant : `foreach (range(n - 1, 0) as $joursEcoules)`, puis `$maintenant->copy()->subDays($joursEcoules)`, soit n jours du plus ancien à aujourd'hui, exactement ceux de l'ancien compteur. Pas une période partie de minuit (`subDays(n - 1)->startOfDay()->daysUntil($maintenant)`) : minuit n'existe pas partout tous les jours. Là où le passage à l'heure d'été saute de 00:00 à 01:00 (America/Santiago, America/Havana, Asia/Beirut, Africa/Cairo, America/Asuncion ; le fuseau se règle par APP_TIMEZONE), chaque jour qui suit tombe à 01:00, et entre 00:00 et 01:00 aujourd'hui dépasse maintenant et sort de la fenêtre : six jours d'hydratation au lieu de sept, vingt-neuf de compléments au lieu de trente. Une période qui doit tenir se borne à la fin d'un jour (`endOfDay()`, `endOfWeek()`), comme la grille de la semaine des habitudes. Un pas en heures (`toPeriod($fin, 24, 'hours')`) compte, lui, deux fois le jour du passage à l'heure d'hiver. Les deux bornes d'une fenêtre se tirent d'une seule lecture de l'horloge (`$debut->copy()->endOfWeek()`, pas un second `now()`) : minuit qui passe entre deux lectures allongeait la fenêtre d'un jour, et la grille de la semaine de sept. `ParcoursDeJoursAutourDeMinuitTest` (tests/Feature/Actions) rejoue les deux. Une énumération plafonnée, qui sort par `break` quand les données manquent, parcourt `range(0, PLAFOND - 1)` et range chaque élément à son rang : la variable du parcours sert alors de clef, là où PHP Insights signalerait une variable inutilisée (`FetchBodyPartMeasurementsIndexAction`).

`AucuneBoucleACompteurDansLeCodeMuteTest` (tests/Feature/Conventions) refuse `for`, `while` et `do` dans les dossiers que la matrice de la passe mute ; il les lit dans le workflow, si bien qu'une part ajoutée y entre d'elle-même.

---
paths:
  - 'app/Actions/**'
  - 'app/Services/**'
  - 'app/Policies/**'
---

# Code muté la nuit

`App\Actions`, `App\Services` et `App\Policies` sont les espaces de noms que la passe de mutation nocturne mute, part par part (`.github/workflows/mutation.yml`). Une écriture qui ne coûte rien à l'exécution peut y coûter une part de la nuit.

## Une boucle à compteur donne un mutant qui ne finit jamais
La passe de mutation remplace `$i++` par `$i--`, ou l'inverse : `for ($i = 0; $i < 7; $i++)` ne s'arrête plus, et le mutant tient un processus de la nuit jusqu'au délai que Pest accorde à chaque mutant (la passe de référence + 20 %), ou jusqu'à épuiser la mémoire. Aucun test ne peut le tuer vite : la boucle ne rend jamais la main. Parcourir ce qui est borné par nature — les jours d'une période (`$debut->daysUntil($fin)->toArray()`, typé, là où l'itération directe d'une `CarbonPeriod` est `mixed` pour PHPStan), une collection, `range()` — plutôt que compter. Vu sur la tendance hebdomadaire du volume, le mutant le plus long de la part `App\Services` : 336 s en local le 07/10 (#2004) ; puis sur quatre parcours de jours d'`App\Actions` (hydratation, compléments, statistiques et semaine des habitudes) : de 60 à 177 s chacun en local le 08/10, près de 580 s cumulées (#2017).

Une période inclut sa fin. Les n derniers jours partent donc du début de la journée (`subDays(n - 1)->startOfDay()`) et vont jusqu'à maintenant : n jours, du plus ancien à aujourd'hui, même quand la fenêtre traverse un changement d'heure, parce que minuit existe tous les jours. Un pas en heures (`toPeriod($fin, 24, 'hours')`) compte, lui, deux fois le jour du passage à l'heure d'hiver. Une énumération plafonnée, qui sort par `break` quand les données manquent, parcourt `range(0, PLAFOND - 1)` et range chaque élément à son rang : la variable du parcours sert alors de clef, là où PHP Insights signalerait une variable inutilisée (`FetchBodyPartMeasurementsIndexAction`).

`AucuneBoucleACompteurDansLeCodeMuteTest` (tests/Feature/Conventions) refuse `for`, `while` et `do` dans les dossiers que la matrice de la passe mute ; il les lit dans le workflow, si bien qu'une part ajoutée y entre d'elle-même.

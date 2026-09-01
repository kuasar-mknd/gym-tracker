---
paths:
  - 'tests/**'
---

# Tests

## Les fonctions d'aide Pest sont globales : préfixer par le sujet
Une `function` déclarée dans un fichier de test Pest est GLOBALE à toute la suite. Deux fichiers qui déclarent `seanceLe()` ou `recordDePoids()` avec des signatures différentes se percutent, et l'erreur remonte en `arguments.count` PHPStan sur le fichier innocent — pas là où est le doublon.

Nommer l'aide d'après ce qu'elle fait ET son sujet : `seanceIlYA()` plutôt que `seanceLe()`, `recordMaxDe()` plutôt que `recordDePoids()`. Avant d'en ajouter une, `grep -rn "function nomChoisi" tests`.

Vu deux fois dans la même session (#1614, #1615).

## Une date posée en dur expire si le code la compare à maintenant
Un littéral comme `'measured_at' => '2026-06-03'` est dans la fenêtre le jour où on l'écrit, et en sort tout seul. Le test échoue alors des mois plus tard, sur une PR qui ne touche à rien de proche — donc on cherche la cause au mauvais endroit.

Vu le 01/09/2026 : `StatsCachePoliciesTest` posait une mesure au 03/06, `BodyStatsService` borne à `now()->subDays(90)`. Quatre-vingt-dix jours pile : la CI est passée au rouge pendant la nuit, sur une PR de cibles tactiles.

Ce n'est pas la date seule qui est en cause, mais le couple **date absolue × fenêtre relative**. Un autre témoin porte la même date du 03/06 et passe, parce que rien ne la compare à `now()`.

Donc : dès qu'un test pose une date que le code comparera à `now()`, arrêter l'horloge (`Carbon::setTestNow(...)`). Cela garde les valeurs littérales, ce qui est leur intérêt — préférable à `now()->subDays(30)` des deux côtés, qui fait un test qui se compare à lui-même.

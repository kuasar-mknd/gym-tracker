---
paths:
  - 'tests/**'
---

# Tests

## Les fonctions d'aide Pest sont globales : préfixer par le sujet
Une `function` déclarée dans un fichier de test Pest est GLOBALE à toute la suite. Deux fichiers qui déclarent `seanceLe()` ou `recordDePoids()` avec des signatures différentes se percutent, et l'erreur remonte en `arguments.count` PHPStan sur le fichier innocent — pas là où est le doublon.

Nommer l'aide d'après ce qu'elle fait ET son sujet : `seanceIlYA()` plutôt que `seanceLe()`, `recordMaxDe()` plutôt que `recordDePoids()`. Avant d'en ajouter une, `grep -rn "function nomChoisi" tests`.

Vu deux fois dans la même session (#1614, #1615).

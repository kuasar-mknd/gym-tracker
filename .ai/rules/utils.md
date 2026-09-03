---
paths:
  - 'resources/js/Utils/SyncService.js, resources/js/Utils/syncErrors.js'
---

# Utils

## La file hors-ligne : quatre verdicts, l'ordre des écritures, et deux évènements à écouter
`classifySyncError` rend quatre verdicts : `offline` (réseau absent, l'écriture est en file), `auth` (401/419, la session ou le jeton CSRF ont expiré : porte fermée, pas refus), `permanent` (4xx, à faire remonter), `transient` (5xx, 429, à réessayer). Depuis #1667 (2026-09-03), un `auth` laisse la file intacte, incrémente `authAttempts` sur l'entrée et émet `sync:auth-required` ; trois portes fermées de suite classent l'entrée refusée (`recordFailure`) pour ne pas bloquer la file. `request()` vide la file avant toute écriture directe et, si elle ne se vide pas, range la nouvelle écriture derrière : c'est ce qui empêche une valeur ancienne rejouée d'écraser une valeur saisie en ligne. `saveQueue()` émet `sync:storage-full` au lieu de lever quand le quota est atteint ; le constructeur lit un stockage corrompu comme une liste vide. Dans les tests, le singleton vide la file à la construction : attendre `service.pending` avant de compter les tentatives (`chargé()` dans `syncService.test.js`).

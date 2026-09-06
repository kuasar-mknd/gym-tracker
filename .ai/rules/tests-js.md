---
paths:
  - 'tests/js/**'
---

# Tests Js

## Suites de page superficielles, mocks de graphiques async et formulaires déplacés dans un composant
Un composant enfant nouveau est stubbé d'office par `shallow: true` : l'ajouter à `stubs` avec le vrai composant, sinon ses sélecteurs disparaissent en silence. Un mock `vi.mock('@/Components/Stats/XChart.vue', …)` doit rendre `{ __esModule: true, default: … }` : sans quoi `defineAsyncComponent` prend le module lui-même pour le composant (« No __isTeleport export is defined on the mock »). Quand un formulaire migre dans un composant, le mock `useForm` du module capture désormais le formulaire de l'enfant, monté après ceux de la page (lire `forms.at(-1)`, pas `forms[0]`), et son remplissage se fait dans un `watch` : `await nextTick()` avant de lire, soumettre par `wrapper.find('form').trigger('submit')`. `import.meta.url` échoue sous Vitest : partir de `jsRoot` (tests/js/conventions/sourceFiles.js).

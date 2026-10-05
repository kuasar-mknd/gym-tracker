---
paths:
  - 'tests/js/**'
---

# Tests Js

## Suites de page superficielles, mocks de graphiques async et formulaires déplacés dans un composant
Un composant enfant nouveau est stubbé d'office par `shallow: true` : l'ajouter à `stubs` avec le vrai composant, sinon ses sélecteurs disparaissent en silence. Un mock `vi.mock('@/Components/Stats/XChart.vue', …)` doit rendre `{ __esModule: true, default: … }` : sans quoi `defineAsyncComponent` prend le module lui-même pour le composant (« No __isTeleport export is defined on the mock »). Quand un formulaire migre dans un composant, le mock `useForm` du module capture désormais le formulaire de l'enfant, monté après ceux de la page (lire `forms.at(-1)`, pas `forms[0]`), et son remplissage se fait dans un `watch` : `await nextTick()` avant de lire, soumettre par `wrapper.find('form').trigger('submit')`. `import.meta.url` échoue sous Vitest : partir de `jsRoot` (tests/js/conventions/sourceFiles.js).

## Un libellé ne coche qu'une case attachée, et les milliers dépendent de la version de Node
Deux pièges vus en #1971 et #1970 (2026-10-05). jsdom suit la spécification : une case détachée du document change d'état au clic sur son libellé, mais n'émet ni `input` ni `change`, et `v-model` ne voit rien. Un test qui clique un libellé monte donc avec `attachTo: document.body` (et démonte). Ensuite, `Intl.NumberFormat('fr-CH')` sépare les milliers par une apostrophe sous Node 24, celui de la CI et de `package.json`, mais par une espace fine insécable sous Node 22 : une douzaine de tests tombent alors sans défaut du code. Lancer Vitest sous Node 24 ; un test nouveau qui doit rester indifférent au séparateur compare à `nombre(…)` plutôt qu'à un littéral.

## Le vrai vue-chartjs sous jsdom : le canevas se lit, le graphique ne se redessine pas
Vu en #1970 (2026-10-05). Sans `vi.mock('vue-chartjs')`, le canevas et ses attributs (`role`, `aria-label`, `aria-describedby`) sont rendus, mais Chart.js refuse le contexte factice de `vitest.setup.js` et le graphique avorte à sa création (« Failed to create chart », sans échec). Quand ses séries changent ensuite, vue-chartjs appelle `update` sur ce graphique avorté, qui lève en rejet non géré : la suite passe, et Vitest sort quand même en erreur. Un test qui change les séries d'un graphique monté pour de vrai neutralise donc `Chart.prototype.update` (`vi.spyOn(…).mockImplementation(() => {})`) et restaure ses mocks après coup, comme `tests/js/components/stats/nomEtResumeDesGraphiques.test.js`.

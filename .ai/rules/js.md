---
paths:
  - 'resources/js/**'
  - 'resources/js/**/*.vue'
---

# Js

## Une couleur ne s'écrit que dans app.css — un composant nomme un rôle
Aucune couleur brute dans `resources/` : ni nuance Tailwind (`bg-slate-800`), ni hexadécimal, ni `rgba()`. Tout vient d'un jeton de `resources/css/app.css`. Huit gardes dans `tests/Feature/Conventions/` le tiennent, dont deux interdictions sèches.

Le piège principal : **ne choisis jamais toi-même la couleur du texte posé sur un fond**. Un jeton de texte unique ne peut pas convenir — l'orange porte du blanc à 4,7:1 et de l'encre à 3,8:1, le vert d'état exactement l'inverse. Emploie un utilitaire apparié (`accent-fill`, `state-fill`, `info-fill`, `danger-fill`, `category-fill-*`, `plate-fill-*`) : il pose le fond ET son texte.

Deux pièges de conversion, appris à nos dépens :
- remplacer une nuance par un rôle change la **luminosité**, pas seulement le nom. `emerald-500` → `accent-state` fait passer un texte blanc de 2,5:1 à 1,2:1 ;
- `bg-red-50 text-red-600` est un **couple lavis/texte**, pas un rôle écrit deux fois. Utilise l'opacité (`bg-accent-danger/10 text-accent-danger-deep`).

Le JavaScript lit les jetons par `Utils/couleurs.js` (`jeton()`, `jetonTransparent()`), jamais une valeur recopiée. Côté PHP — courriels, pages d'erreur, widgets Filament — c'est `App\Support\Charte`.

`@theme static` est obligatoire dans `app.css` : Tailwind n'émet sinon que les variables qu'une classe utilise, et les jetons lus uniquement par le JS sont absents du CSS compilé (les graphiques dessinent alors en noir).

## Un bouton passe par son composant, jamais par du markup recopié
Trois composants couvrent l'essentiel : `GlassButton` (action avec libellé), `GlassIconButton` (action réduite à une icône) et `GlassBigNumber` (le grand champ chiffré des calculateurs). Écrire un `<button>` à la main, c'est perdre la cible de 44 px, l'anneau de focus, l'état de chargement, et surtout la capacité de suivre la charte quand elle bouge — c'est ainsi que le « + » de la bibliothèque s'est retrouvé en dégradé alors que son jumeau desktop était en `primary`.

Trois règles se tiennent dans `tests/js/conventions/buttonVariants.test.js` et `touchTargets.test.js` :
- tout `type="submit"` DÉCLARE sa variante (l'absence retombe sur `default`, le verre pâle des actions tertiaires) ;
- « Annuler » est toujours `secondary`, jamais `ghost` : refuser et confirmer ne doivent pas se ressembler ;
- toute création (« Ajouter », « Créer », « Nouveau », l'icône `add`) est `primary` ;
- un bouton réduit à une icône atteint 44 px, par `min-h-touch`, par une taille explicite, ou par un `before:-inset-*` qui déborde sans pousser ses voisins.

Sur `GlassSelect`, `placeholder` est une INVITE (rendue `disabled`) et `empty-label` est un CHOIX vide sélectionnable. Les confondre donnait un « — Aucune — » sur lequel personne ne pouvait revenir.

## Glisser-déposer au doigt : les quatre pièges, tous payés au prix fort

`@formkit/drag-and-drop` saisit au PREMIER mouvement sans regarder la direction — elle n'expose aucun seuil directionnel. Une rangée qui doit aussi glisser latéralement ne peut donc pas arbitrer par l'espace : c'est le temps qui tranche (`longPress`, 220 ms). Et `longPress` ne marche que si le geste ne peut pas devenir un défilement : la bibliothèque appelle `preventDefault` sur le `pointerdown` APRÈS son minuteur, ce qui n'annule pas un défilement déjà engagé sur iOS.

Elle pose `draggable` sur le NODE — l'enfant direct du conteneur, donc la racine du composant de rangée, pas le div qu'on habille. Au doigt, iOS s'en saisit après une demi-seconde et fabrique son propre aperçu, qui vole le geste. `-webkit-user-drag` est une impasse et non un correctif mal placé : `caniuse-lite` le donne `"n"` sur Safari iOS pour TOUTES les versions, alors que l'API de glisser HTML5 y est `"y"` depuis la 15 — donc `dragstart` est bien émis et annulable. Une media query est une impasse pour une autre raison : elle décrit l'appareil quand le défaut décrit un geste, et un iPad au trackpad se déclare pointeur fin tout en restant tactile. La coupure se fait sur le type du dernier `pointerdown`, dans `useListeReordonnable.js`. Ne pas passer `nativeDrag: false` : la souris de bureau n'a QUE ce chemin, le chemin synthétique se retirant pour elle.

Elle déplace aussi le nœud elle-même sur le chemin tactile. Vue, restée sur l'ancien arrangement, écrit ensuite les numéros dans les mauvaises rangées. On reconstruit les rangées par une génération portée dans la clef du `v-for`.

Corollaire de vérification, le plus coûteux : jsdom ne rejoue pas le chemin tactile, et des évènements de pointeur synthétiques dans un navigateur non plus. Ces défauts ne se reproduisent que sur simulateur, avec `touch_path` et une vraie temporisation — un appui trop court y passe pour un défilement et fait conclure à tort que rien ne marche. Un témoin utile porte sur le mécanisme (identité des éléments DOM, `defaultPrevented`, appel du rappel), jamais sur le rendu final.

## Un graphique déclare ses séries, `BaseChart` fait le reste

`resources/js/Components/Stats/BaseChart.vue` porte l'unique `ChartJS.register(...)` de l'application, l'infobulle, la légende, les axes et la hauteur. Une carte ne garde que ses `labels`, ses `datasets` et ce qui la distingue vraiment. Une garde de `tests/js/conventions/chartChunks.test.js` vérifie qu'aucun autre fichier n'importe `chart.js` ou `vue-chartjs`.

Ce que la carte passe en props plutôt qu'en options recopiées : `type`, `hauteur`, `legende` (absente : cachée sur des barres ou une courbe, visible sous un anneau), `infobulle` (`{ accent, opaque, …réglages Chart.js }`), `axeX`/`axeY` (`false` cache l'axe **sans perdre son échelle**), `axeY1`, `indexAxis`, `interaction`, `lueur` + `lueurOpacite` (à la place d'un `<style scoped>` qui ne portait qu'un `drop-shadow`), `plugins`, `vide` avec les créneaux `#vide` et `#surcouche`. Le créneau `options` fusionne en dernier, branche par branche : il est là pour le cas non prévu, pas pour rebâtir l'habillage.

Trois règles tenues à la main, parce qu'une factorisation « sans changement de comportement » a reproduit des divergences que personne n'avait comparées à l'écran (retour de Sam, 2026-09-05) : **les dates d'un axe s'écrivent `jj/mm`** (`etiquetteDeDate` côté client, `format('d/m')` côté serveur) ; **une carte de page montre ses deux axes**, seuls les encarts du tableau de bord (`RecentWorkouts*`, `RecentPRs`) et les vignettes de la page de statistiques (`compact`) restent nus ; **un anneau a ses parts habillées par `BaseChart` et sa légende sous le canevas**, et une carte qui fixe la hauteur de son graphique reçoit `hauteur="h-full"` plutôt que d'y laisser un vide. Avant de livrer une carte, la regarder à côté de ses voisines.

Deux pièges payés pendant la migration des 48 cartes : `BaseChart` ne pose `beginAtZero` que sur des barres, donc une courbe qui l'attendait doit le redemander par `:axe-y` ; et le composant s'importe en chemin relatif depuis le dossier des cartes, jamais par l'alias, que la garde imposant `defineAsyncComponent` refuse sur tout fichier de graphique.

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

## Une poignée de glisser-déposer dans SwipeableRow doit porter data-swipe-ignore
`SwipeableRow` s'arme sur toute la rangée : ses dix premiers pixels arbitrent entre glissement latéral et défilement. Une poignée posée dedans se dispute donc le doigt, et la saisie paraît marcher une fois sur deux selon la trajectoire — pas selon l'endroit. Toute poignée à l'intérieur doit porter `data-swipe-ignore`.

Corollaire de vérification : jsdom ne rejoue pas le chemin tactile de `@formkit/drag-and-drop`, et des évènements de pointeur synthétiques dans un navigateur non plus. Les défauts de ce geste ne se reproduisent que sur simulateur, avec `touch_path`. Un témoin utile porte sur le mécanisme (identité des éléments DOM, appel du rappel), jamais sur le rendu final.

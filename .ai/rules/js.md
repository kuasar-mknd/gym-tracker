---
paths:
  - 'resources/js/**'
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

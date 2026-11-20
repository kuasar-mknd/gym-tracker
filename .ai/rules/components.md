---
paths:
  - 'resources/js/Components/**'
---

# Components

## Enfant fixed dans un conteneur space-y
Un enfant `fixed` d'un conteneur `space-y-*` reçoit la marge basse du conteneur (12 px avec space-y-3) : sa position `bottom` s'en trouve relevée. Ajouter `mb-0`. Et ce qui flotte au-dessus du menu du bas se pose avec `au-dessus-du-menu` (variable `--hauteur-du-menu`, 66 px à 320 px, 70 au-delà), jamais avec un `bottom-sure-*` fixe : l'écart de 6 px est gardé par AbandonDUneSeanceVideTest. Mesurer avec getBoundingClientRect, pas par l'arithmétique des classes.

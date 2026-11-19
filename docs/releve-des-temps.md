# Relever ce que coûte chaque écran

Cette page explique comment chiffrer le temps de réponse de chaque écran, avant puis après un changement de performance. `SERVER_TIMING_ENABLED` reste à `false` le reste du temps.

## Faire un relevé

1. Poser `SERVER_TIMING_ENABLED=true` dans l'environnement de la pile.
2. Recréer le service `app` : la variable se lit au démarrage.
3. Ouvrir l'application connecté, par un chargement complet de la page (après la connexion, recharger).
   - Chaque réponse faite à un compte porte alors l'en-tête `Server-Timing`.
   - Il n'y en a jamais pour un invité, une authentification ou un 404.
4. Lire le relevé (voir « Où lire le relevé »).
5. Relever une dizaine de fois chaque écran de la liste « Quoi relever ».
6. Publier le tableau en commentaire de l'issue (voir « Publier le tableau »).
7. Remettre `SERVER_TIMING_ENABLED=false`, recréer le service, et le dire dans le commentaire.

## Ce que porte l'en-tête

| Mesure | Contenu |
| --- | --- |
| `app` | durée de l'application |
| `routage`, `controleur`, `rendu` | les trois étapes de `app` |
| `sql` | durée cumulée et nombre des requêtes SQL, par exemple `sql;dur=12.4;desc="7 requetes"` |
| `cache` | durée cumulée et nombre des lectures du cache, une par clé, par exemple `cache;dur=1.9;desc="8 lectures"` |

## Où lire le relevé

| Appareil | Comment |
| --- | --- |
| Ordinateur | L'onglet Réseau des outils du navigateur (Timing) montre l'en-tête de chaque requête. Dans la console, `releverLesTemps()` rend le relevé en tableaux Markdown. Chaque navigation est aussi une mesure de l'onglet Performance, `navigation /chemin`, du toucher à la première image peinte. |
| Téléphone, sans outils | Ouvrir une page de l'application dans le navigateur avec `#releve-des-temps` au bout de l'adresse, puis la recharger. |

Sur un téléphone, le relevé s'affiche replié en haut de l'écran, sous l'en-tête, loin du minuteur de repos.

- Un toucher sur son titre le déplie ou le replie.
- Replié, il ne calcule rien. Déplié, il se tient à jour au plus une fois par seconde, jamais pendant une navigation, qu'il allongerait.
- « Copier le tableau » le copie.
- « Fermer » le retire et oublie ce réglage, gardé sur l'appareil seulement.
- L'application installée peut ne pas voir un réglage fait dans le navigateur (sur iOS, elle garde son propre stockage) : faire alors le relevé dans le navigateur.

## Quoi relever

Relever une dizaine de fois chacun de ces écrans :

- l'accueil (sa page, puis sa prop différée) ;
- les séances ;
- les statistiques ;
- la séance en cours ;
- l'ajout d'une série (`POST /api/v1/sets`) ;
- la modification d'une série (`PATCH /api/v1/sets/{id}`).

## Lire le tableau

Par requête, en médiane et en p95 :

| Colonne | Sens |
| --- | --- |
| TTFB | délai avant le premier octet |
| hors de l'app | écart entre le TTFB et `app` : ce qui entoure l'application (réseau à l'aller et au retour, proxy inverse, attente d'un worker libre) |
| `app`, `routage`, `controleur`, `rendu` | temps de l'application et de ses étapes |
| `sql`, `cache` | durée et nombre |
| service worker | passage ou non par le service worker |

Le tableau donne aussi, par destination, la durée des navigations.

- **« Hors de l'app » compte l'aller-retour du réseau.** Pour y lire une attente de worker, le comparer d'abord à celui des requêtes dont `app` est le plus court, afin d'en ôter l'aller-retour.
- **Une écriture d'Inertia que le serveur redirige** (302 ou 303 vers la page suivante) s'écrit « → redirigée ». La page suit la redirection dans la même requête : ses colonnes sont celles de la page d'arrivée, et la colonne « redirection » donne le temps de l'écriture elle-même, réponse de redirection comprise.
- Le `Server-Timing` de cette réponse de redirection ne se lit pas dans la page. Sur un ordinateur, l'onglet Réseau la montre à part.

## Publier le tableau

- Publier le tableau tel qu'il sort : il ne porte que des chemins relatifs, sans chaîne de requête, chaque identifiant devenu `{id}`.
- Ne jamais joindre d'adresse complète, d'en-têtes bruts, de capture de l'onglet Réseau ni de fichier HAR : ils nommeraient le domaine ou ce qui précède l'application.

## Confidentialité

Rien ne quitte l'appareil : le relevé vit en mémoire, se perd au rechargement, et dort tant que la page complète ne porte pas l'en-tête (`resources/js/Utils/releveDesTemps.js`).

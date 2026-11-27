# Fonctionnement hors ligne

Cette page explique ce que l'application installée (PWA) garde sur l'appareil, et comment elle s'ouvre et enregistre sans réseau. Elle décrit un fonctionnement, pas une procédure.

Trois choses vivent hors ligne :

| Quoi | Où c'est décrit |
| --- | --- |
| Les modifications faites sans réseau, en attente d'envoi | [Modifications en attente](#modifications-en-attente) |
| Les copies des pages utiles en salle | [Copies des pages de salle](#copies-des-pages-de-salle) |
| L'ouverture de l'application sans réseau | [Ouverture sans réseau](#ouverture-sans-réseau) |

Une clé, portée par un cookie de session, protège les copies : elles ne se lisent que tant que ce cookie vit.

## Modifications en attente

### Où elles attendent

- Dans le stockage du navigateur, **en clair**, au nom de leur compte.
- Elles ne partent que sous la session de ce compte.

Une séance démarrée, terminée ou lancée d'un modèle sans réseau y attend de la même façon, avec l'heure du geste. Pour la démarrer, l'appareil tire une clé que le serveur garde avec la séance : un envoi rejoué ne la crée jamais deux fois.

### À la déconnexion

Si la déconnexion trouve des modifications en attente, ou part pendant qu'une modification attend encore sa réponse, l'application le dit d'abord, avec leur nombre.

| Choix | Effet |
| --- | --- |
| « Rester connecté » | Les modifications sont gardées. |
| « Se déconnecter quand même » | Elles sont effacées de l'appareil, avec les refus, les brouillons de séries et la dernière recherche d'exercice. L'écran de connexion dit combien. |

### Autres cas

- **Un autre onglet resté ouvert sur le compte** le dit aussi : ce qu'on y modifie ensuite sans réseau n'est plus gardé, et la valeur d'avant revient, avec un message.
- **Une session que le serveur ferme** laisse les modifications pour le retour du compte.
- **La dernière recherche d'exercice** est rangée avec son compte : aucun autre compte ne la lit, et la première page de connexion l'efface.

### Durée de garde : trente jours

Aucune modification n'attend plus de trente jours. Au-delà, elle est abandonnée et effacée.

- Le compte lit un avis, avec le nombre de modifications et la date, à sa prochaine ouverture sur cet appareil, s'il revient dans les trente jours qui suivent.
- L'avis ne garde que l'identifiant du compte, ce nombre et cette date. Il est effacé passé ce délai : un compte qui revient plus tard n'en voit rien.
- Si le compte se déconnecte avant d'avoir vu l'avis, l'écran de connexion le dit.

### Compte supprimé

- Une suppression faite depuis l'appareil efface aussitôt les modifications en attente.
- Pour un compte supprimé depuis un autre appareil ou depuis le panneau, elles partent à la première ouverture de l'application sur l'appareil, une fois passés trente jours : aucune réponse du serveur ne dit qu'un compte n'existe plus.

### Le cookie de la clé

Toute réponse faite à un compte connecté pose le cookie qui porte la clé des copies :

| Réglage | Nom du cookie |
| --- | --- |
| `SESSION_SECURE_COOKIE` vrai (production) | `__Host-gt_copies` |
| `SESSION_SECURE_COOKIE` faux | `gt_copies` |

- La page lit ce cookie.
- La clé est tirée au hasard, pour la session. Le serveur la garde dans la session.
- Toute réponse qui dit la fin de la session l'efface : déconnexion (depuis l'application comme depuis le panneau, dont la session est la même), mot de passe changé ailleurs, compte supprimé, session expirée.
- Il expire avec la session : au bout de `SESSION_LIFETIME` minutes sans requête, ou de douze heures quand l'appareil se souvient du compte (« Se souvenir de moi »).

## Copies des pages de salle

Pour s'ouvrir plus tard sans réseau, l'application garde sur l'appareil une copie des pages utiles en salle, telles que le serveur les a rendues.

### Pages copiées

| Copiées | Jamais copiées |
| --- | --- |
| l'accueil ; la séance en cours et les trois dernières séances closes visitées ; la liste des séances ; la liste des modèles, avec de quoi lancer chacun sans réseau ; la bibliothèque d'exercices ; la racine de Progrès, sans une seule mesure ; le profil ; les outils ; le minuteur d'intervalles ; les disques ; le 1RM ; l'échauffement | le journal, les mesures, la santé, les habitudes, les compléments, les notifications, les objectifs ; tout formulaire ; toute page d'invité |

Deux précisions :

- La copie de l'accueil ne garde pas les objectifs en cours qu'il affiche.
- La copie de Progrès ne garde ni le poids, ni sa variation, ni la masse grasse, ni les historiques : hors ligne, la page dit que les mesures reviennent avec le réseau.
- Aucune copie ne garde le message qu'une page affiche après un enregistrement.

### Où et comment elles sont gardées

| Aspect | Valeur |
| --- | --- |
| Lieu | base IndexedDB du navigateur, `gym-tracker-copies` |
| Portée | le seul compte connecté |
| Chiffrement | AES-GCM 256, par la clé que porte le cookie |
| Gardé en clair | l'adresse de chaque page, son nom de composant, deux dates, une empreinte de la clé (qui ne permet pas de la retrouver) et l'identifiant du compte |
| Taille de l'ensemble | sous 1,5 Mo |

La clé ne vit que dans le cookie. La session finie, quelle qu'en soit la cause, les copies ne se lisent plus, même application fermée.

La page de séance y verse la séance telle qu'elle est enregistrée. Une note, en clair, garde l'identifiant du compte et l'heure de la dernière modification acceptée de chaque séance ; elle est effacée avec les copies.

### Quand elles sont effacées

- Avant l'envoi de la déconnexion, même quand elle échoue faute de réseau.
- Avant l'envoi de la suppression du compte : la clé de la session est alors fermée avec les copies, jusqu'à la fin de la session. Sauf quand le serveur refuse la suppression (le mot de passe).
- Quand un autre compte ou un invité ouvre l'application sur l'appareil.
- Quand le serveur dit la fin de la session.
- À chaque ouverture, pour celles qui ont plus de sept jours.

### Comment elles se remplissent

Après une réponse du serveur, l'application demande elle-même :

- les pages de la liste qui lui manquent ou ont plus de deux heures : huit requêtes au plus, une fois par demi-heure ;
- la séance en cours, dès qu'une modification faite sans réseau y est enfin enregistrée.

Ces requêtes ne prolongent pas la session et ne posent aucun cookie. Une réponse revenue après une copie plus récente ne la remplace pas.

### Ce qu'une copie ne sait pas

Un compte supprimé, ou dont le mot de passe a changé ailleurs, pendant que l'appareil est hors ligne, reste lisible par l'application sur cet appareil jusqu'au premier contact avec le serveur, ou jusqu'à l'échéance du cookie.

### Dans l'application ouverte

Quand le réseau ne répond pas, un lien vers l'une de ces pages ouvre sa copie :

- tout de suite, sans réseau ;
- au bout de cinq secondes, sur un réseau qui ne répond plus ;
- seulement si la copie est celle du compte de la page affichée, sa clé présente jusqu'au moment où elle s'affiche.

Une réponse arrivée avant les cinq secondes passe toujours devant la copie.

Ce que la personne voit :

- **Un bandeau** dit l'état (« Hors ligne », ou « Réseau lent ») et date la copie (« Copie du 20 nov. à 05:02 »), avec « Réessayer ».
- **Ce que la copie n'a pas** (les objectifs, les graphiques, ce que la page charge après elle) est dit indisponible hors ligne.
- **Les boutons qui enregistreraient** se désactivent et disent pourquoi. Rien ne part d'une copie, sauf la déconnexion et ce qui passe par la file : démarrer ou terminer une séance, et lancer un modèle tel que la copie le montre.
- **La séance** garde ses modifications en file, comme avant.
- **Un lien vers une autre page** laisse la page en place et dit qu'elle n'est pas disponible sans réseau.

À la première réponse du serveur, la copie affichée se recharge d'elle-même, la séance une fois ses modifications enregistrées :

- ses formulaires et la bibliothèque d'exercices de la séance reprennent les valeurs du serveur, sauf ce que la personne y a changé ;
- ses graphiques se dessinent ;
- un formulaire qui se remplirait des valeurs de la copie, comme la modification d'un préréglage du minuteur, ne s'ouvre pas sur elle.

### Fichiers de l'application

Pour que les pages s'affichent, le service worker garde à l'installation les fichiers de l'application et de ces pages, sauf leurs graphiques. Le temps d'une mise à jour, il garde aussi ceux de la version précédente.

- Ce sont des fichiers construits, les mêmes pour tous : jamais une copie ni une donnée de compte.
- Un stockage à court de place n'empêche pas la nouvelle version de s'installer : seuls les fichiers de la précédente manquent alors.

## Ouverture sans réseau

### Quand la page « hors ligne » est servie

L'accueil, une séance, les séances, les modèles, les exercices, le profil et les outils attendent le serveur huit secondes au plus. Passé ce délai, ou sans réseau, le service worker sert la page « hors ligne ». Les autres pages la reçoivent aussi sans réseau.

La page « hors ligne » est servie sous les mêmes protections que les pages du serveur : politique de contenu, cadrage, origine, et `no-store`, comme les pages d'un compte.

Elle n'est jamais servie pour :

- le retour d'un fournisseur de connexion ;
- le panneau et ses tableaux de bord ;
- les quatre liens des courriels : trois sont signés (vérification de l'adresse, ouverture du compte, confirmation d'une nouvelle adresse), le quatrième porte un jeton (nouveau mot de passe).

Ces pages attendent le serveur sans délai.

### Ce que fait la page « hors ligne »

1. Elle vérifie la clé du cookie, le compte de l'appareil et la version des copies. À la première vérification qui échoue, elle efface les copies.
2. Elle démarre l'application sur la copie de la page demandée, avec le même bandeau.

Pages qui s'ouvrent ainsi :

| Page | Précision |
| --- | --- |
| Accueil | |
| Séance en cours | Les séries qui attendent leur envoi restent marquées comme telles. Les minuteurs, qui ne vivaient qu'en mémoire, sont perdus. |
| Séance démarrée sans réseau | Elle se rouvre depuis ce qui attend son envoi, à l'adresse de sa clé. |
| Séances, modèles, exercices, profil, outils | |

Une adresse sans copie montre ce qui s'ouvre sans réseau.

Ce démarrage ne vient que de la page que le service worker sert : le même fichier ouvert à sa propre adresse ne montre rien d'un compte.

### Quand il n'y a aucune copie lisible

La page dit « Pas de réseau », puis : « Ouvre l'application une fois avec du réseau avant la salle : l'accueil, la séance en cours, les séances, les exercices et les outils resteront disponibles pendant la durée de ta session. »

- Elle se recharge au retour du réseau.
- Quand le serveur a seulement tardé, elle propose « Attendre la réponse » : l'adresse attend alors le serveur sans délai pendant une minute.

### Quand le serveur revient

Une copie ouverte ainsi se recharge en entier à la première réponse du serveur. Elle se recharge aussi d'elle-même quand la page demandée arrive après le délai : le serveur était lent, pas absent, et l'adresse l'attend alors sans délai pendant une minute.

### Durée de vie des copies

**Les copies vivent le temps de la session.** Elles ne se lisent que tant que vit le cookie qui porte leur clé :

| Cas | Durée |
| --- | --- |
| Cas général | `SESSION_LIFETIME` minutes après la dernière réponse du serveur (deux heures par défaut) |
| L'appareil se souvient du compte (« Se souvenir de moi ») | douze heures |
| Plafond | aucune copie n'est servie au-delà de sept jours |

S'entraîner le soir sans réseau après avoir ouvert l'application chez soi l'après-midi suppose donc « Se souvenir de moi » coché, ou une réponse du serveur dans ce délai. La case est décochée par défaut, et la connexion par un fournisseur ne la coche jamais.

### Ce qui est vérifié

Ces parcours se vérifient dans Chrome. Sur iOS, IndexedDB, le precache et leur éviction dans l'application installée restent à vérifier sur un appareil.

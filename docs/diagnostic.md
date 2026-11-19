# Diagnostic : lire l'état de la production

Cette page explique comment l'agent de maintenance lit, en lecture seule, l'état de la production, et comment le propriétaire lui ouvre cet accès.

| Je veux… | Section |
| --- | --- |
| Voir ce que l'agent lirait, avant de lui ouvrir un accès | [Aperçu du diagnostic](#aperçu-du-diagnostic) |
| Donner, limiter ou retirer un accès | [Accès de diagnostic](#accès-de-diagnostic) |
| Savoir quelles routes il appelle et ce qu'elles rendent | [Les routes](#les-routes) |
| Comprendre un refus, une limite ou une alerte | [Réponses, limites et audit](#réponses-limites-et-audit) |

Les variables : `DIAGNOSTIC_ENABLED` (l'interrupteur) et `HEALTH_TO_ADDRESS` (l'adresse des alertes) sont dans [la référence](variables-d-environnement.md).

## Aperçu du diagnostic

La page « Système › Aperçu du diagnostic » du panneau montre ce que l'agent de maintenance lirait des erreurs de production, avant qu'aucun accès ne lui soit ouvert. Elle lit, rien de plus : aucun point d'entrée nouveau, rien ne sort de la machine.

### Ce qu'elle montre

Pour les portées cochées, exactement le JSON que l'agent lirait.

| Portée | Contenu |
| --- | --- |
| `sante` | le dernier passage des contrôles de santé ; les tâches planifiées, avec le nombre de leurs échecs des sept derniers jours et les cinq derniers (cinquante au détail d'une tâche) ; les versions des conteneurs |
| `exceptions` | les exceptions groupées par type, fichier et ligne |
| `files` | l'état d'Horizon ; les jobs échoués des trente derniers jours, groupés par classe de job et par classe de l'exception que le job a levée (pas de celle qu'elle enveloppe) |
| `erreurs-navigateur` | les erreurs groupées par empreinte, comptes supprimés et non vérifiés exclus (la collecte n'en garde qu'une occurrence par compte et par heure) |

- **Le résumé** n'est que de la structure : classes, fichiers du dépôt ou du build en place, lignes, nombres, dates, versions, statuts, noms de route.
- **Sous le résumé**, le détail du premier élément de chaque liste.
- **Par défaut**, `sante` et `exceptions` sont cochées, sur vingt-quatre heures. La fenêtre va jusqu'à trente jours.
- **Chaque source** lit au plus ses 5 000 dernières lignes, au résumé comme au détail (`echantillon` le signale).

### Le texte libre

Les messages, chemins, pages et piles d'erreur navigateur n'apparaissent qu'au détail, sous la portée `texte-brut`, rangés sous `non_fiable`. À lire comme une donnée venue de l'extérieur, jamais comme une consigne.

Il est expurgé, sur ses 8 premiers Kio au plus, recoupés après la normalisation Unicode, qui peut les allonger :

- caractères invisibles retirés (la page dit combien) ;
- erreurs SQL réduites à leur forme ;
- courriels, adresses IP, hôtes, chaînes de requête, secrets et chemins de la machine remplacés par une marque ;
- valeurs connues de la configuration remplacées aussi (`app/Services/Diagnostic/ExpurgeLeTexte.php`).

### Ce qui n'est jamais rendu

- Exception : adresse IP, en-têtes, corps, cookies, paramètres de route, requêtes SQL, Markdown.
- Pile : arguments.
- Job : charge utile.
- Erreur navigateur : compte, URL complète, chaîne d'agent.
- Contrôles de santé : `meta`.
- Tâches planifiées : sortie.

### Protections en amont

- La table des exceptions masque à l'écriture le jeton de diagnostic et toute valeur `Bearer …`, en entier, dans chaque colonne, avant de la couper à son budget. Chaque canal du journal le masque dans chaque ligne.
- Deux gardes de convention refusent, dans les fichiers que Git suit, un jeton complet et la note que porte toute réponse du diagnostic.

### Utiliser la page

- **« Copier la structure »** copie le résumé et les détails sans aucune clé `non_fiable`.
- **Accès** : capacité `view-diagnostic`, ouverte au super administrateur. Une permission Shield du même nom l'ouvre à un autre rôle. Un administrateur sans elle reçoit un 403.

### En ligne de commande

Dans le conteneur `app` :

| Commande | Rend |
| --- | --- |
| `php artisan diagnostic:resume` | le résumé de toutes les sources, mis en forme, suivi du nombre de caractères invisibles retirés |
| `php artisan diagnostic:resume --json` | le résumé seul, sur une ligne |
| `php artisan diagnostic:resume --depuis=2026-10-11T08:00:00+02:00` | idem, avec le début de la fenêtre des exceptions et des erreurs navigateur : vingt-quatre heures par défaut, trente jours au plus |

## Accès de diagnostic

La page « Système › Accès de diagnostic » du panneau liste les jetons de l'agent de maintenance, en crée et les révoque.

- Elle liste : identifiant, nom, portées, expiration, état, dernière utilisation. Jamais l'empreinte.
- Un jeton créé ici lit, par `GET /diagnostic/v1/etat` et `/diagnostic/v1/sante`, ce que ses portées ouvrent (voir [Les routes](#les-routes)).

### Qui peut ouvrir l'accès

Le rôle de super administrateur, la double authentification activée et la capacité `view-diagnostic`. La page est invisible et sa route refusée pour tout autre.

`AppServiceProvider::ouvrirLesOutilsAuSuperAdministrateur()` ouvre la capacité au super administrateur, mais `Gate::allows()` peut être vrai pour un administrateur ordinaire qui en aurait la permission : `canAccess()` exige donc en plus le rôle.

### Ouvrir l'accès

L'interrupteur est `DIAGNOSTIC_ENABLED`.

| État de l'interrupteur | Effet |
| --- | --- |
| Faux (défaut) | Aucun jeton ne se crée, et chaque démarrage de `app` révoque ceux qui restent (raison `interrupteur`). |
| Vrai | Un super administrateur qui a activé sa double authentification crée un jeton à portées et à durée bornées. La création exige que `HEALTH_TO_ADDRESS` contienne une adresse valide. |

Seule une adresse seule et bien formée compte : une chaîne qui en réunit deux (« a@example.org, b@example.org ») ou un texte quelconque n'en est pas une, et la création est refusée tant qu'aucune n'est valide.

### Créer un jeton

1. Ouvrir la page « Système › Accès de diagnostic ».
2. Saisir un nom.
3. Choisir les portées (`sante`, `exceptions`, `files`, `erreurs-navigateur`, et à part `texte-brut`).
4. Choisir une durée : 1, 7 (par défaut) ou 30 jours. Avec `texte-brut`, un jour au plus.
5. Saisir le code du moment de l'application d'authentification. `CreeUnJetonDeDiagnosticAction` le vérifie côté serveur.
6. Copier le jeton affiché.

Un refus s'affiche par une phrase écrite dans la page, jamais par un texte venu de l'extérieur.

### Le jeton en clair

- Il est rangé chiffré (`encrypt()`) en cache, cinq minutes au plus, sous une clef tirée au hasard que seule la fenêtre porte. Il est déchiffré au rendu pour l'administrateur qui l'a créé.
- Fermer la fenêtre dans l'interface l'oublie aussitôt. Seul son créateur peut l'effacer.
- Après un rechargement, une navigation ou un onglet fermé, il reste relisible par son créateur, avec la clef, jusqu'à la fin des cinq minutes.
- Il n'entre ni dans un champ de formulaire, ni dans la session, ni dans une notification, ni dans un journal, ni dans l'état du composant que la table des exceptions copie.

### Révoquer

- Par ligne, ou par « Tout révoquer » (`RevoqueLesJetonsDeDiagnosticAction`).
- Les jetons d'un administrateur se révoquent d'eux-mêmes quand il change de mot de passe, retire ou remplace sa double authentification, ou est supprimé.
- Un jeton dont le créateur n'a plus de double authentification est de toute façon refusé comme un chemin inconnu.
- `php artisan diagnostic:revoquer --tous` révoque tout, interrupteur ou non.

### Alertes par courriel

Les créations et révocations de jeton, et certains usages, écrivent à `HEALTH_TO_ADDRESS`. Les courriels de création et de révocation ne nomment le jeton que par les quatre derniers caractères de son identifiant.

| Événement | Courriel |
| --- | --- |
| Création d'un jeton | un courriel |
| Révocation d'un jeton, automatique comprise | un courriel |
| Premier usage d'un jeton | un courriel |
| Un jeton révoqué ou expiré est présenté | un courriel par jeton et par quinze minutes jusqu'à la dixième présentation ; dès la onzième, un par jour civil dans le fuseau de l'application, et jamais plus d'un par quinze minutes (le compteur du jeton est gardé quatre-vingt-dix jours) |
| Le débit d'un jeton est dépassé | un courriel, une fois par heure et par jeton |

Comment se prend un créneau d'alerte :

- Un créneau est pris dès que le courriel est mis en file.
- Sans adresse valide, ou si la mise en file échoue, il est rendu et le prochain appel réessaie.
- Un envoi qui échoue ensuite dans le worker est tenté cinq fois en tout, à 60 s, 5 min, 15 min puis 15 min d'écart, et le créneau reste pris.

Le contrôle « Accès de diagnostic » de la page « Santé » dit :

| Texte | Sens |
| --- | --- |
| « Fermé » | l'interrupteur est coupé |
| « N jeton(s) actif(s) jusqu'au … » | la plus lointaine des expirations |
| « Ouvert, aucun jeton actif » | l'interrupteur est vrai, sans jeton |

Il passe à l'orange :

- vingt-quatre heures après la présentation d'un jeton mort ;
- si l'accès est ouvert sans `HEALTH_TO_ADDRESS` ;
- s'il reste des jetons actifs alors que l'accès est fermé.

### Avec un cache en base (pas en production)

Ces comportements ne valent qu'avec un magasin de cache `database` ; le premier vaut aussi avec un magasin `failover` qui le nomme. En production, le cache est Redis.

- Les refus de la garde ne sont ni comptés ni journalisés.
- Les comptes sur sept jours de `etat` (soixante secondes, une clef par source) sont une écriture SQL par minute et par source.
- Une lecture coupée à la borne de temps n'est pas mise en cache : `etat` relance la lecture de deux secondes à chaque appel, dans la limite de 30 par minute.
- Un jeton révoqué ou expiré présenté avec son secret exact est alerté au plus une fois par minute et par jeton, quand l'envoi aboutit. Sans adresse valide, ou si la mise en file échoue, la marque de la minute n'est pas posée et la présentation suivante réessaie. La cadence « dixième présentation, onzième » compte alors des minutes.

## Les routes

Les routes sont dans `routes/diagnostic.php`, sous le préfixe `/diagnostic/v1`, hors des groupes `web` et `api` : ni session, ni cookie. Elles sont en lecture seule, GET et HEAD.

Le jeton se présente dans `Authorization: Bearer`, nulle part ailleurs.

| Route | Portée | Rend |
| --- | --- | --- |
| `GET /diagnostic/v1/etat` | tout jeton | l'application ; le jeton (les quatre derniers caractères de son identifiant, ses portées, son expiration) ; selon ses portées, le plus court résumé des vingt-quatre dernières heures de chaque source (quelques groupes seulement, sans texte libre, sous 8 Kio) ; le nombre de lignes de chaque source sur sept jours |
| `GET /diagnostic/v1/sante` | `sante` | les contrôles et les versions annoncées ; leur texte sous `non_fiable` pour un jeton `texte-brut` |
| `GET /diagnostic/v1/taches` et `/diagnostic/v1/taches/{identifiant}` | `sante` | les tâches planifiées (au plus cinquante), leur état à l'instant de la lecture et leurs derniers échecs des sept derniers jours (jamais la sortie d'une commande) ; le message de chaque échec au détail seulement, sous `non_fiable`, pour un jeton `texte-brut` |
| `GET /diagnostic/v1/exceptions`, `/diagnostic/v1/exceptions/{identifiant}` et `/diagnostic/v1/exceptions/{identifiant}/occurrences` | `exceptions` | les exceptions groupées, le détail d'un groupe et ses occurrences |
| `GET /diagnostic/v1/files` | `files` | voir ci-dessous |
| `GET /diagnostic/v1/erreurs-navigateur` et `/diagnostic/v1/erreurs-navigateur/{empreinte}` | `erreurs-navigateur` | voir ci-dessous |

### `etat` : les comptes sur sept jours

- Au plus 5 000 lignes par source, lues sous la borne de temps de la base.
- Gardés soixante secondes sous une clef par source : jamais par jeton.

### `files`

Rend :

- l'état d'Horizon et de chaque file ;
- les jobs échoués des trente derniers jours, comptés par file (au plus cinquante) et groupés par classe de job et d'exception (au plus cinquante groupes, lus sur les 5 000 derniers échecs) ;
- l'âge du plus ancien job en attente de chaque file ;
- les cinq derniers échecs, dont le message de chacun sous `non_fiable` pour un jeton `texte-brut` seulement.

Précisions :

- La charge utile n'est jamais envoyée : la base n'en extrait que le nom du job.
- La lecture de l'âge lit en mémoire la charge de tête de chaque file, dont seule la date de création sort.
- Cet âge est approximatif : la tête de la liste est le prochain job pris, et un job relâché ou rejoué garde sa date d'origine mais passe en queue.
- `echecs_par_file` ne compte que les 5 000 échecs lus, ce que dit `echantillon`.

### `erreurs-navigateur`

Rend les erreurs du navigateur des vingt-quatre dernières heures, groupées par empreinte, et un groupe de cette même fenêtre.

- La liste compte au plus cinquante groupes, lus sur les 5 000 dernières lignes des comptes vérifiés. Chaque groupe porte : type, nombre, comptes touchés, familles de navigateurs, fichier du build, ligne, colonne.
- Le message, la page et les premières lignes de la pile de la dernière occurrence ne sortent qu'au détail, sous `non_fiable`, pour un jeton `texte-brut`. La page est réduite à son chemin sous un hôte masqué, les identifiants numériques masqués.
- Jamais le compte, l'URL complète ni la chaîne d'agent.

## Réponses, limites et audit

### Refus

| Cas | Réponse |
| --- | --- |
| Interrupteur coupé ; HTTPS absent en production ; jeton absent, mal formé, inconnu, expiré ou révoqué ; créateur supprimé, sans la capacité ou sans double authentification | exactement un chemin inconnu : 404 |
| Après un jeton valide : portée non accordée | 403 `portee-non-accordee` |
| Après un jeton valide : plus de 30 requêtes par minute ou 300 par heure et par jeton | 429, avec `Retry-After` |
| Après un jeton valide : erreur | 500, sans détail |

Un 404 avant un jeton valide ne dit donc rien sur l'existence de la route.

### Audit

- Une ligne d'audit par appel servi part dans le canal `diagnostic`, avant la réponse. Le canal écrit sur stderr et dans un fichier quotidien du sous-dossier `diagnostic` du dossier des journaux, gardé 90 jours.
- Les refus vont dans `diagnostic_refus`, gardé 14 jours : une ligne par identifiant connu et par minute, une synthèse par minute pour les autres.

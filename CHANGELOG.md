# Journal des modifications

Toutes les modifications notables de GymTracker seront documentées dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/),
et ce projet adhère au [Versionnage Sémantique](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Modifié
- **L'ordre de la séance vit dans un composable** (#1675) : `useOrdreDeLaSeance` porte le réordonnancement des exercices et des séries, au doigt comme aux flèches, l'écriture de l'ordre entier au serveur et son repli à l'ordre confirmé ; la page de séance passe sous deux mille lignes (2 527 → 1 904 depuis le début de son éclatement)
- **La page de séance confie aussi son rapport de synchronisation à un composable** (#1675) : `useRapportDeSynchronisation` porte les séries non synchronisées, les deux canaux d'alerte (le toast de la mise en page, le message posé six secondes sur une correction refusée), la lecture des refus de la file hors ligne et leur annonce au montage ; le minuteur du message est désormais arrêté quand la page se ferme. Encore cent soixante lignes de moins
- **Les records qui tombent partent en une seule écriture** (#1668) : retirer un exercice qui portait trois records coûtait trois `delete` séparés, un par record ; la reconstruction les regroupe désormais en une instruction. Le contrat d'écritures par opération couvre ce cas
- **La page de séance confie ses brouillons et ses valeurs confirmées à un composable** (#1675) : premier pas de son éclatement, `useBrouillonsDeSeries` porte ce que le serveur détient de chaque série et ce que l'écran n'a pas encore réussi à lui faire accepter, y compris leur rejeu au montage, avec ses propres tests ; la page perd cent cinquante lignes sans changer de comportement
- **Le volume total d'un utilisateur se lit dans ses séances, il n'est plus tenu à chaque série** (#1670) : chaque série validée payait une écriture `update users` en plus de celle de la séance, la plus chère des trois sur le serveur de production ; le total se lit désormais par une somme, la colonne disparaît, et le contrôle nocturne ne surveille plus que les séances. Un test tient le contrat : valider une série n'écrit rien dans `users`
- **La page des séances tient en huit requêtes à froid au lieu de quatre-vingt-huit** (#1670) : le compte des exercices distincts sautait d'index en index par une requête par exercice ; le saut tourne désormais dans la base, en une seule instruction et avec les mêmes lectures. Un test tient la page sous quinze requêtes

## [1.5.11] - 2026-09-05

### Ajouté
- **Une sauvegarde quotidienne de la base, chiffrée, hors du conteneur** (#1663) : à 02:30 le planificateur archive la base dans le dossier de l'hôte `BACKUP_HOST_PATH` (nettoyage à 02:00, contrôle de fraîcheur à 08:00, chaque tâche surveillée par Sentry) ; la pile exige `BACKUP_HOST_PATH` et `BACKUP_ARCHIVE_PASSWORD` au démarrage et les transmet à `app`, `worker` et `scheduler` ; sans mot de passe, aucune archive n'est écrite, que la demande vienne du planificateur, de Filament ou de `backup:run`

### Corrigé
- **Les recommandations pré-calculées par lot étaient écrites sous une clef que personne ne lisait** : la lecture passe par une clef versionnée par utilisateur, l'écriture par lot ne l'était pas, donc chaque ligne recalculait ses valeurs. Trouvé en tuant les mutants de `RecommendedValuesService`

### Modifié
- **Un seul graphique de base pour les 48 cartes de statistiques** (#1675) : chaque carte recopiait l'enregistrement de Chart.js, l'habillage de son infobulle, de sa légende et de ses axes ; elle ne déclare plus que ses séries et ce qui la distingue. Huit listes d'enregistrement divergentes deviennent une, tenue par une garde, et les trois gris de grille, les cinq bordures d'infobulle et les polices de graduation qui avaient dérivé se rejoignent. La densité de l'ombre et la place de la légende restent réglables, elles portaient une intention. Solde : 1 788 lignes de moins
- **Les statistiques en cache portent une version par utilisateur** (#1670) : invalider, c'est incrémenter la version (séances ou mesures), et toutes les entrées deviennent inatteignables d'un coup ; plus de liste de clefs à tenir à jour, celle qui avait déjà oublié une entrée (#1502). Renommer une séance recalcule aussi le volume hebdomadaire à la prochaine lecture.
- **L'autorisation d'une ressource vit au contrôleur** (#1676) : dix requêtes de validation refaisaient la vérification que le contrôleur fait déjà ; elles ne vérifient plus que la connexion, sauf `goals.update` où la règle `exists` ferait travailler la base avant le refus (le contrat de non-divulgation le mesure). Une requête de validation morte est retirée.
- **Un seul formulaire de modèle de séance** (#1675) : les pages de création et de modification, identiques à 95 %, partagent le composant `TemplateForm` ; deux pages de trente lignes au lieu de deux copies de 450.

### Infrastructure
- **Les tests navigateur de la CI tournent en trois éclats** (#1672) : le job faisait 62 % du chemin critique ; la répartition est un glouton sur des durées mesurées en CI (un fichier nouveau reçoit le poids médian), les trois éclats sont agrégés sous un seul contrôle requis

## [1.5.10] - 2026-09-04

### Corrigé
- **« Tes préférences n'ont pas pu être enregistrées » alors qu'elles l'étaient** : la page enregistre en XHR et le serveur répondait par une redirection 302 que le navigateur rejouait en `PATCH /profile/edit`, donc 405 ; il répond désormais 204 à un client XHR. Et la bannière « Activer les notifications » revient quand le navigateur n'a plus d'abonnement push, même si le serveur en garde un.

### Modifié
- **Le service worker et le manifeste sont des fichiers statiques** à la racine de `public/` (le manifeste est versionné, le worker construit) : plus de route Laravel pour les servir, donc plus de session ouverte ni de cookie posé à chaque vérification du worker (une écriture de moins sur le NAS par ouverture de l'application).
- **La création d'une série suit un seul chemin** (#1676) : le contrôleur cherchait la ligne et vérifiait le droit d'y écrire, puis l'action refaisait les deux ; une lecture de moins par série, et une seule autorisation à relire.
- **La façade `StatsService` disparaît** (#1676) : dix-sept de ses dix-huit méthodes relayaient vers les services de statistiques spécialisés ; chaque appelant reçoit désormais le service qu'il utilise, et la vue « performance » du tableau de bord est composée par l'action qui la sert, sous la même clef de cache.
- **Un seul réordonnancement** (#1676) : les deux actions jumelles (séries d'une ligne, exercices d'une séance) n'en font plus qu'une, qui vérifie elle-même que l'ordre soumis est une permutation ; et les deux requêtes de validation identiques des disques n'en font plus qu'une.

### Infrastructure
- **L'audit des dépendances npm passe par la base OSV** (#1708) : `osv-scanner` épinglé scanne `package-lock.json` entier, même seuil qu'avant (CVSS ≥ 7), sans tolérance ; `npm audit` dépendait d'un endpoint du registre npm tombé par intermittence toute la journée du 4 septembre.

## [1.5.9] - 2026-09-04

### Corrigé
- **Le service worker ne s'installait jamais en production** (#1683) : sa liste de precache pointait sur `/assets/…` au lieu de `/build/assets/…`, chaque entrée répondait 404 et Workbox annulait l'installation. Conséquences depuis la 1.5.0 : pas de mode hors-ligne réel, et l'activation des notifications push bloquée à l'étape « Service worker ». Un contrôle en CI vérifie désormais chaque entrée.

## [1.5.8] - 2026-09-03

### Corrigé
- **La file hors-ligne ne perd plus d'écritures** (#1667) : une session ou un jeton expirés pendant que la PWA dormait (401, 419) classaient l'écriture refusée et vidaient la file derrière elle ; l'écriture reste maintenant en attente, la page est prévenue, et tout repart après reconnexion (trois portes fermées de suite classent l'écriture refusée pour ne pas bloquer la file). Une écriture faite en ligne alors que la file attendait pouvait être écrasée par une plus ancienne rejouée après elle : la file se vide d'abord, ou la nouvelle écriture prend rang derrière. Un stockage illisible ne fait plus échouer le chargement de la page, et un stockage plein ne bloque plus la file.

### Modifié
- **Moins d'écritures par action** (#1670) : le journal d'activité ne suit plus que les comptes (`User`, `Admin`) et non les six modèles métier qui y écrivaient à chaque modification sans lecteur ; il se lit désormais dans le panneau d'administration (« Journal d'audit », lecture seule, permission Shield dédiée) et se purge chaque nuit au-delà de 180 jours. Les recommandations de séries et les trois caches de la liste des séances sont invalidés dès qu'une série ou un exercice change, au lieu d'attendre leur expiration. La synchronisation des records ne part en file qu'après validation de la transaction. Un test fige le nombre d'écritures de chaque opération de la page de séance (trois par série créée, modifiée ou supprimée, trois par exercice retiré).

## [1.5.7] - 2026-09-03

### Corrigé
- **Un record personnel créé par l'API accepte un type hors énumération et une valeur sans borne** (#1665) : un type inconnu passait la validation puis cassait la lecture du record, et 99 999 999 kg étaient acceptés, ce qui débloquait les succès de poids. Le type est validé contre l'énumération et les valeurs sont bornées à 100 000, à la création comme à la modification.

### Sécurité
- **Moins de données sortent de l'application** (#1666) : Sentry ne reçoit plus l'e-mail ni le nom de l'utilisateur, seulement son identifiant, et le Session Replay est coupé ; la table des routes Ziggy injectée dans chaque page passe de 310 routes (33 Ko) à 111 (10 Ko), sans aucune route d'administration ni d'API hors des sept servies ; les origines CORS locales ne sont autorisées qu'en environnement local ; `unsafe-eval` ne sort plus que sur le panneau d'administration ; le journal d'échec de création d'une série ne contient plus la pile ni la charge utile.
- **Le panneau d'administration ne s'ouvre plus par défaut** (#1664) : le seeder exige `ADMIN_INITIAL_PASSWORD` et ne réécrit jamais le mot de passe d'un compte existant ; la valeur par défaut `CHANGE_THIS_PASSWORD` disparaît de la configuration et de `.env.example` ; une ligne dans la table `admins` ne suffit plus, il faut un rôle ou une permission Shield ; et une liste blanche d'IP vide ferme le panneau en production. **Avant de déployer, renseigner `ADMIN_ALLOWED_IPS`.**
- **Les sept routes API qui restent vérifient le jeton CSRF** (#1673) : `api/*` était exempté de la vérification, la session Sanctum n'étant protégée que par `SameSite=lax`.

### Modifié
- **Ménage de l'audit** (#1669) : la CI annule le run précédent d'une PR et borne chaque job dans le temps, le job `audit` ne dépend plus des tests, `semgrep` et `actionlint` sont épinglés par version, un cache `vendor` absent est réinstallé au lieu de casser trois étapes plus loin ; `entrypoint.sh` n'avale plus un échec de migration ; `AGENTS.md` et `GEMINI.md` ne sont plus que des renvois vers `CLAUDE.md`, gardés par un test ; le README annonce les vrais seuils (PHPStan `max`, mutation 80 / 95 / 99) ; la production journalise les dépréciations PHP.
- **La liste blanche du panneau accepte les plages CIDR** (#1664) : `ADMIN_ALLOWED_IPS` prend des adresses exactes ou des plages, IPv4 et IPv6, pour couvrir un réseau local ou un tailnet sans lister chaque appareil.

### Retiré
- `laravel/breeze` et la déclaration directe de `firebase/php-jwt` (tiré par Socialite), `serialize-javascript`, `autoprefixer` et `postcss` (Tailwind 4 s'en passe), les captures d'échec Dusk commitées, `ci-verified-ship.skill`, `setup_db.sh` et l'échafaudage Pest (`toBeOne`, `something()`) (#1669).
- Les quatre documents de mars qui décrivaient une autre application (la feuille de route, l'analyse de restructuration, le plan de performance et l'enquête Dusk, tous sous docs/) et .agent/workflows/, quatrième emplacement d'instructions (#1671). Un garde de convention vérifie désormais que chaque chemin cité dans un document existe et que chaque version annoncée est celle des manifestes.
- L'API REST complète, jamais consommée : 24 contrôleurs, 35 requêtes de validation, 20 ressources, 62 fichiers de tests, la spec OpenAPI, `l5-swagger` et son gate CI. Restent les sept routes que la page de séance appelle (#1673).

## [1.5.6] - 2026-09-03

### Corrigé
- **Le bouton « Activer » des notifications push pouvait tourner sans fin** (#1683) : une étape du navigateur qui ne répond jamais (service worker jamais actif, abonnement jamais fourni) bloquait le bouton, et tout échec donnait le même message. Chaque étape est désormais nommée sur le bouton, bornée à 20 s, et le message d'échec dit laquelle a cassé, avec la réponse du serveur quand c'est lui qui refuse.

## [1.5.5] - 2026-09-03

### Corrigé
- **La première série d'un exercice ajouté en séance partait à 0 kg** (#1677, #1678) : ajouter un exercice déjà pratiqué et appuyer sur « + série » avant la réponse du serveur préremplissait la série à 0, les suivantes la copiaient, et la ligne à 0 devenait « la dernière fois » pour les propositions des séances suivantes. Une série encore intacte prend désormais la recommandation quand la ligne est créée, un champ déjà saisi garde sa valeur, et le service ignore les séries restées au pré-remplissage en remontant jusqu'à cinq séances en arrière, une série de poids de corps restant un historique.

### Modifié
- **Le rappel d'entraînement part à 18 h, les jours choisis** (#1681) : il partait à minuit après un nombre de jours d'inactivité ; il part désormais à 18 h, les jours de la semaine cochés dans le profil (tous par défaut), et seulement si aucune séance n'a commencé dans la journée. Une préférence sans jours choisis vaut « tous les jours ».

### Infrastructure
- **Une sonde de santé propre au planificateur** (#1679) : le service `scheduler` héritait du `HEALTHCHECK` de l'image, qui interroge un serveur web absent de ce conteneur ; il était « unhealthy » à vie dans Portainer. Il vérifie désormais que l'application démarre et liste ses tâches.

## [1.5.4] - 2026-09-02

### Ajouté
- **Réorganisation des exercices d'une séance** (#1659) : au glisser-déposer depuis une poignée, sur une séance en cours d'au moins deux exercices. La carte suit le doigt au pixel — `GlassCard` porte `transition-all duration-300`, et animer le `transform` la faisait traîner loin derrière. Le serveur réécrit la liste entière en une seule requête, un `case` sur la clef primaire : l'écriture ne dépend pas du nombre d'exercices, et aucun ordre intermédiaire n'est lisible entre deux mises à jour. Les flèches du clavier déplacent aussi.
- **Réorganisation des séries d'un exercice** (#1661) : `sets` n'avait aucune colonne d'ordre — les séries étaient triées par identifiant, donc par ordre de création. La migration l'ajoute et la renseigne depuis les identifiants existants, pour que personne n'ait à réordonner une séance qu'il n'a pas touchée. La rangée entière se saisit après 220 ms d'appui ; le glissement immédiat reste la suppression, seule façon d'effacer une série sur téléphone.
- **Réorganisation des exercices d'un modèle** (#1648) : deux flèches par carte. L'ordre n'était modifiable qu'en supprimant les exercices pour les ressaisir, avec leurs séries. `workoutTemplateLines()` demande désormais son tri explicitement : la relation était un `hasMany` nu, que MySQL servait trié par accident de plan.
- **Objectif sur une mensuration de partie du corps** (#1657) : tour de taille, poitrine et bras avaient été proposés puis retirés parce qu'ils rendaient `Unknown column 'waist'` — une erreur 500 à chaque pesée. Ces mesures existent, mais dans `body_part_measurements` : une ligne désignée par son nom, pas une colonne. Les treize parties du produit sont proposées sous le nom exact de la saisie, et les objectifs créés avant le retrait suivent de nouveau leurs mesures. Un objectif "Poids de corps" annonçait par ailleurs des centimètres, et `PUT /api/v1/goals/{goal}` était la seule porte à ne pas borner `measurement_type` : elle rend désormais 422 hors des valeurs connues.

### Modifié
- **Démarrage automatique du minuteur de repos, rendu optionnel** (#1651) : valider une série ouvrait toujours le minuteur, qui se pose en `z-[9999]` au-dessus de la séance, sans échappatoire. Le réglage se bascule depuis le minuteur lui-même et renvoie en arrière plutôt que vers le profil — on ne doit pas sortir d'une séance pour ça. Les commandes passent de cinq à trois.

### Optimisé
- **Premier chargement du tableau de bord** (#1646) : `ActiveGoalsChart` était le seul des neuf graphiques importé en direct, ses huit voisins passant par `defineAsyncComponent` — la première page après connexion payait donc Chart.js en entier, et rien ne le signalait puisque le patron était partout ailleurs respecté. Le JS statique de la page tombe de 772,9 à 382,5 Kio bruts, de 257,1 à 126,4 Kio transférés. Un garde interdit désormais d'importer `chart.js` hors d'un composant de graphique.
- **Règle de découpage des morceaux resserrée** (#1659) : elle filtrait sur `/vue/`, donc tout paquet exposant un sous-chemin Vue tombait dans le morceau que *chaque* page charge. Elle vise maintenant `node_modules/vue/`.

### Corrigé
- **Le repos ne pouvait plus être chronométré une fois le démarrage automatique coupé** (#1656) : plus rien n'ouvrait le minuteur, et l'interrupteur qui le rallume vit dedans. Le réglage était donc irréversible depuis l'interface — alors que c'est le moment choisi qu'on voulait rendre à l'utilisateur, pas la fonction. Un bouton "Démarrer un repos" ouvre le panneau sur la durée du compte.
- **La croix du minuteur se posait par-dessus le bouton de pause** (#1655) : en `absolute` au-dessus d'une rangée en flux, elle recouvrait 28 sur 20 px visibles et 34 sur 26 px de zone tactile. Un appui sur le coin de la pause fermait donc le minuteur, et la croix blanche se lisait mal sur l'orange. Les deux rejoignent la même grappe : deux cibles de 44 px séparées de 8 px.
- **Treize boutons-icône sous la cible de 44 px** (#1652) : le garde des cibles tactiles ne filtrait que sur `material-symbols`, donc un bouton dont l'icône est un SVG en ligne lui était invisible — neuf suppressions parmi les treize, et un à 20 px sur l'écran de séance. Le garde reconnaît désormais le SVG seul et *lit* la taille déclarée par l'icône au lieu de supposer les 24 px d'une ligature ; sans cette seconde moitié, il n'en aurait rattrapé aucun.
- **L'aperçu du mois pouvait montrer trois exercices au hasard** (#1650) : il prend les trois premiers exercices d'une séance après un tri sur `(workout_id, order)`, qui n'est pas un ordre total — rien n'interdit à deux lignes d'une même séance de partager un rang, l'index n'étant pas unique. La base rendait alors ce qu'elle voulait. Départagé par identifiant, gratuit puisque l'index secondaire porte déjà la clef primaire.
- **Deux records personnels du même type sur le même exercice** (#1631) : l'index `(user_id, exercise_id, type)` n'était pas unique et les deux chemins d'écriture font `$pr ??= new PersonalRecord(...)`, donc deux écritures concurrentes créaient deux lignes. `recompute()` les indexait ensuite par `keyBy()`, qui ne garde que la dernière : la première n'était ni mise à jour ni supprimée et annonçait indéfiniment une valeur que plus rien ne soutenait. Une migration écarte les doublons — la ligne au plus grand identifiant est conservée, sa valeur restant à recalculer au prochain `--repair` — puis pose la contrainte d'unicité.
- **Un ordre nul rendait 500 au lieu de 422** (#1647) : deux requêtes de mise à jour de modèle déclaraient `order` `nullable` et passaient `validated()` tel quel à `update()`, envoyant un `null` dans une colonne `NOT NULL`. À la création, `nullable` reste juste : un `null` y demande d'ajouter à la fin.

## [1.5.1] - 2026-08-30

### Sécurité
- **`nanoid` (branche 3.x)** : `postcss` tire la 3.3.17, dans la plage de [GHSA-2v37-7h3g-55p8](https://github.com/advisories/GHSA-2v37-7h3g-55p8) — un générateur personnalisé boucle indéfiniment sur une taille nulle. Contrainte en `^3.3.18`, portée à la branche 3 seule. À ne pas confondre avec l'avis précédent, [GHSA-28wg-ghj8-5hjv](https://github.com/advisories/GHSA-28wg-ghj8-5hjv), qui visait la 5.x tirée par `radix-vue` : deux avis distincts, deux branches, et celui de la 5.x ne s'applique plus depuis que `radix-vue` a été retiré.
- **`nanoid`** : la contrainte `^5.1.16` a été posée parce que `radix-vue` épinglait la 5.1.6, dans la plage de [GHSA-28wg-ghj8-5hjv](https://github.com/advisories/GHSA-28wg-ghj8-5hjv) — un générateur non sécurisé qui boucle indéfiniment sur une taille négative. `radix-vue` ayant été retiré comme dépendance jamais importée, plus aucune 5.x n'est installée : `npm ls nanoid --all` ne montre que `postcss → nanoid@3.3.17`, branche que l'avis ne concerne pas. L'`override` est retiré avec son sujet, plutôt que laissé en place à décrire une situation qui n'existe plus.

- **Polices auto-hébergées.** Les quatre requêtes bloquantes vers `fonts.googleapis.com` sont supprimées : la PWA installée n'avait aucune police hors-ligne, chaque démarrage à froid attendait un tiers, et l'IP de chaque visiteur partait chez Google au chargement. Les faces sont servies par l'application, empreintées par Vite et précachées par le service worker — `woff2` manquait au glob de précache, donc les ajouter sans ça n'aurait rien changé pour l'offline. `fonts.googleapis.com` et `fonts.gstatic.com` sont retirés de la CSP ; `fonts.bunny.net` reste, Horizon, Telescope, Pulse et Filament s'en servant pour leurs tableaux de bord.
- **Jeu d'icônes réduit à ce qui est affiché** : la face variable complète de Material Symbols pesait 1 099 Kio et couvrait tout le catalogue. Le sous-ensemble des 86 icônes réellement rendues pèse 10,0 Kio. `tests/Feature/IconSubsetTest.php` échoue si un composant rend une icône absente du jeu — sans quoi elle s'afficherait en toutes lettres.

### Corrigé — en marge des dépendances
- **`Permissions-Policy` malformé** : l'en-tête déclarait `vr=()`, un nom de brouillon jamais entré au registre. Les navigateurs rejetaient le jeton et le signalaient sur chaque réponse (154 fois par passe de tests navigateur, sans que rien ne l'attrape, `assertNoConsoleExceptions` ne regardant que les erreurs `SEVERE`). WebXR n'était donc pas bloqué du tout. Le nom correct est `xr-spatial-tracking`.
- **`Archivo Black` était chargé pour un champ que rien n'affiche** : `glass-input-fat` n'a qu'un consommateur, `GlassFatInput.vue`, qu'aucune page n'importe et que `main.js` n'enregistre pas — extrait de `GlassInput` en #795 sans jamais être branché, puis entretenu pendant quatre PR. Servir la police n'a donc rien changé à l'écran, contrairement à ce qu'annonçait cette entrée. Composant, utilitaire, jeton `--font-fat` et les deux faces (15,8 Kio) sont retirés ; le grand champ numérique reste à écrire le jour où une séance en voudra un.
- **`Barlow Condensed` était téléchargé en six graisses et jamais rendu** : l'utilitaire `font-condensed` n'a aucun usage. Police et jeton retirés.

### Modifié
- **`tailwind.config.js` supprimé.** Tailwind v4 ne charge un config JS que via un `@config` explicite, absent du projet : les 54 jetons et les 5 keyframes du fichier vivaient déjà dans le bloc `@theme` d'`app.css`, seule source de vérité depuis la migration v4. Le `Dockerfile` ne le copie plus.
- **La prose ne génère plus de CSS.** La détection de contenu de v4 scanne tout fichier non ignoré par git, y compris le markdown. `tailwind.config.js` n'était donc pas qu'inerte : ses clés de `boxShadow` étaient lues *comme du contenu* et faisaient émettre trois utilitaires que plus aucun composant n'utilise. Même chose pour un exemple de code dans `AGENTS.md`, qui maintenait un `gap-8` en vie. Le markdown ne rend rien : `@source not` l'exclut désormais du scan. Quatre règles mortes en moins, **−389 octets** de CSS. Leurs `@utility` restent définis dans `app.css` et seront de nouveau émis dès qu'un composant s'en servira.
- **Dépendances portées à leur dernière version.** Au-delà des mises à jour compatibles :
    - **spatie/laravel-query-builder 6 → 7** : les méthodes `allowed*()` sont devenues strictement variadiques, donc les 22 sites d'appel passent leurs filtres, tris et inclusions un par un plutôt qu'en tableau. La config publiée est réalignée (`count_suffix` et `exists_suffix` fusionnés en `suffixes`, `disable_invalid_includes_query_exception` au singulier, ajout de `delimiter` et `filter_value_splitting_enabled`).
    - **spatie/laravel-activitylog 4 → 5** : les changements suivis quittent `properties` pour une colonne `attribute_changes`, et `batch_uuid` disparaît avec le système de lots. Une migration réécrit les lignes existantes ; sans elle tout l'historique s'afficherait comme une modification vide.
    - **Inertia 2 → 3**, serveur et client ensemble.
    - **laravel-notification-channels/webpush 10 → 11**, **laravel/mcp 0.5 → 0.9**, **laravel/boost 2.4 → 2.5**.
    - **Image de build Node 25 → 24 (LTS)** : la CI testait les assets en 24 et l'image en construisait d'autres en 25.
    - **Redis 7.4 → 8** en production, où le `compose.yaml` de développement suivait déjà la 8.
    - **actions/stale v10 → v11**.

### Corrigé
- **Séries d'une séance** (#1319) :
    - **Durée des exercices cardio et chronométrés** : le champ durée écrivait `NaN` sur la série tant que ses segments étaient incomplets, ce qui réinitialisait le champ pendant la frappe et enregistrait `null` en base. La valeur n'est plus lue qu'une fois complète, et le formatage d'une durée repose sur de l'arithmétique plutôt que sur `Date` (plus de repli silencieux au-delà de 24 h, plus de `RangeError` en plein rendu).
    - **Valeurs numériques** : les champs poids, reps, distance et durée sont normalisés en nombres (`''` devient `null`) au lieu de circuler comme chaînes.
    - **Ordre des séries** : `WorkoutLine::sets()` trie désormais explicitement par `id`. Sans `ORDER BY`, MySQL pouvait rendre les séries triées par poids via `sets_workout_line_id_weight_reps_index`, ce qui les déplaçait dès qu'un poids était corrigé. `Workout::workoutLines()` départage par `id` les lignes partageant la même valeur `order`.
    - **Création de séries successives** : les `POST` d'un même exercice sont sérialisés, deux séries ajoutées coup sur coup ne pouvant plus être écrites dans l'ordre inverse des taps.
    - **Valeurs fantômes selon le type d'exercice** : une nouvelle série était créée avec les quatre mesures quel que soit l'exercice. Une série cardio partait donc avec `reps: 10` et un poids de 0, et une série chronométrée avec en plus `distance_km: 0` — des champs que sa ligne n'affiche même pas et que personne n'avait saisis. C'est de là que venait un 10 apparu de nulle part : c'est la valeur de pré-remplissage des reps, écrite dans des lignes qui n'ont pas de reps. Une série ne porte plus que ce que son exercice mesure.
    - **Saisie rapide** : une réponse périmée n'écrase plus une valeur plus récente, un refus rétablit la dernière valeur confirmée par le serveur, la validation d'une série attend l'envoi des valeurs en attente, et les brouillons hors-ligne sont conservés par champ.

## [1.4.28] - 2026-04-10

### Sécurité
- **Audit de Sécurité (Sentinel)** : Mise à jour de `phpseclib/phpseclib` vers la version **3.0.51** pour corriger la vulnérabilité **CVE-2026-40194** (attaque par analyse temporelle sur les comparaisons HMAC SSH2).

### Optimisé
- **Performance Backend (Bolt)** :
    - Remplacement des boucles `updateOrCreate` par des opérations `upsert` massives dans `UpdateNotificationPreferencesAction` pour réduire le nombre de requêtes SQL (#1126).
    - Optimisation de la mise en cache des boucles dans `RecommendedValuesService` (#1125).
- **Architecture & Code** : Suppression d'un paramètre `Request` inutilisé dans `WorkoutController::store` (#1124).

### Modifié
- **Accessibilité & Design (Palette)** : Standardisation des composants d'interface PWA, amélioration du support du mode sombre et renforcement de l'accessibilité globale.

### Corrigé
- **Infrastructure CI/CD (Pixel)** :
    - Synchronisation du fichier `package-lock.json` avec `package.json` pour résoudre les échecs de compilation des images Docker (linux/amd64 et linux/arm64) lors de l'étape `npm ci`.

## [1.4.26] - 2026-04-07

### Ajouté
- **Visualisations Avancées** : Intégration de nouveaux graphiques (Chart.js) pour les historiques de PRs, la progression des objectifs, la durée des jeûnes, la fréquence des entraînements par jour, et la durée des séances.
- **Suivi des Entraînements** : Ajout du suivi de la session d'entraînement active avec bannière persistante, actions dynamiques sur bouton flottant (FAB), et meilleur affichage des modales.

### Modifié
- **Design Liquid Glass** : Application systématique du design "Liquid Glass" aux sections de statistiques (RecentVolume, TimeOfDay, Duration), aux vues de confirmation de mot de passe, au suivi d'eau (WaterTracker), au formulaire du journal, et à divers composants de liste.
- **UX & Accessibilité (Palette)** :
  - Support de la navigation au clavier (focus, aria-labels) sur de multiples composants et boutons (Journal, bascules personnalisées, dropdowns, etc.).
  - Raccourci clavier de recherche (`⌘K`).
  - Sélection automatique des données des séries au focus pour accélérer la saisie (`Auto-select`).
  - Amélioration de l'ergonomie des messages flash (toasts de confirmation et d'erreur avec fermeture automatique).

### Optimisé
- **Performance de l'Interface (Bolt)** :
  - Consolidation massive des propriétés différées (`Inertia::defer`) sur l'ensemble des vues de statistiques, de tableau de bord, et des index d'entraînements, pour un chargement instantané de la vue initiale.
  - Résolution des requêtes N+1 et de l'hydratation Eloquent via `toBase()` et des requêtes optimisées dans les services de recommandation, l'historique d'eau, et les commandes de rappels d'entraînement.
  - Amélioration de l'efficacité du calcul de la plus longue série d'assiduité (`max streak`) dans `AchievementService`.

### Sécurité
- **Audit de Sécurité (Sentinel)** :
  - Correction d'un manque de protection contre le brute-force sur la confirmation de mot de passe (Faille de niveau ÉLEVÉ).
  - Ajout de limitations de requêtes (rate limiting) manquantes sur la suppression de compte.
  - Ajout et correction des autorisations explicites manquantes au niveau des méthodes dans plus de 10 contrôleurs de l'API (IntervalTimer, Warmup, MacroCalculation, WorkoutLine, etc.).
  - Retrait du suivi des fichiers `.env` contenant de fausses ou potentielles données sensibles de l'index Git et ajout strict au `.gitignore`.

### Corrigé
- **Développement & Tests (Pixel)** :
  - Couverture massive des fonctionnalités avec Pest (SetController, HabitController, StatsController, Actions, etc.)
  - Mise à jour et amélioration de la stabilité des suites E2E (Dusk) sur les téléphones de type iPhone Mini / iPhone Max (Correction des exceptions d'éléments expirés pour l'édition de séances).
- **Infrastucture Front-end** :
  - Compatibilité Vite / Rolldown : Correction des chunks manuels empêchant la compilation du code JavaScript en environnement CI et production.

## [1.4.24] - 2026-03-24

### Ajouté
- **Visualisations Avancées** : Introduction de nouveaux graphiques Chart.js pour le volume de session, l'historique du 1RM estimé, la distribution des muscles et la progression du poids des séries.
- **Calculatrices Fitness** : Ajout d'outils pour le calcul des macros, du score Wilks, et des plaques de poids.
- **Support PWA Complet** : Activation finale des notifications Push et du Service Worker pour une expérience mobile native.

### Modifié
- **Refonte UI Liquid Glass** : Passage au système de conception "Liquid Glass" sur l'intégralité de l'application (Dashboard, Formulaires, Profil, Entraînements).
- **Consolidation des Stats** : Migration des statistiques lourdes vers des propriétés différées (`Inertia::defer`) pour des temps de chargement initiaux divisés par 10.
- **Interaction Palette** : Standardisation des retours d'interaction avec la directive `v-press` et amélioration globale de l'accessibilité (Aria-labels, visibilité du focus).

### Optimisé
- **Architecture 2026** : Restructuration complète vers un modèle basé sur les DTOs, les Actions et des Services granulaires.
- **Performance SQL** : Résolution massive de requêtes N+1 et implémentation d'insertions par lots pour les modèles d'entraînement.
- **Hydratation Eloquent** : Optimisation de la récupération des modèles et réduction de l'empreinte mémoire des jobs de synchronisation.

### Sécurité
- **Sentinel Security** : Corrections critiques de vulnérabilités IDOR dans les contrôleurs d'entraînements et d'habitudes.
- **Mass Assignment** : Protection renforcée contre l'assignation de masse des IDs de fournisseurs OAuth.
- **Modernisation** : Mise à jour des en-têtes de sécurité CSP et suppression des méthodes de hachage redondantes.

### Corrigé
- **Infrastructure CI** : Résolution des corruptions de métadonnées MySQL 8.4 dans les tests longs.
- **Stabilité Dusk** : Correction du crash du seeder sur les Enums et ajustement des zones de sécurité pour les écrans iPhone Max.
- **Qualité Code** : Application systématique des règles Rector et obtention d'un score de 100/100 sur les métriques PHP Insights.

## [1.4.23] - 2026-03-17

### Ajouté
- **Architecture 2026** : Introduction d'Enums PHP 8.5 pour les types de records (`PersonalRecordType`), les objectifs (`GoalType`) et les catégories d'exercices (`ExerciseCategory`).
- **Services Spécialisés** : Décomposition du `StatsService` monolithique en services granulaires : `VolumeStatsService`, `BodyStatsService`, `WorkoutStatsService`, `ExerciseStatsService` et `StatsCacheManager`.
- **Extraction Logique Métier** : Création de `RecommendedValuesService` pour isoler la logique de calcul des suggestions, allégeant le modèle `WorkoutLine`.

### Modifié
- **Refactorisation du Dashboard** : Décomposition de `Dashboard.vue` en 8 sous-composants spécialisés pour une maintenabilité accrue.
- **Réorganisation des Composants** : Restructuration complète de `resources/js/Components/` avec des dossiers `UI/`, `Form/` et `Navigation/`.
- **Organisation des Tests** : Reorganisation des tests Feature dans des sous-dossiers thématiques (`Controllers/`, `Models/`, `Services/`).
- **Squash des Migrations** : Consolidation de 73 migrations en un seul fichier de schéma (`schema:dump`) pour une initialisation de base de données ultra-rapide.

### Optimisé
- **Nettoyage de la Racine** : Suppression des fichiers de logs CI parasites et mise à jour du `.gitignore`.
- **Standardisation i18n** : Traduction des dernières chaînes hardcodées dans le backend vers les fichiers de langue JSON.
- **Modernisation PHP** : Mise à jour de la documentation et des configurations vers PHP 8.5.

## [1.4.18] - 2026-03-06

### Ajouté
- **Recommandations Intelligentes** : Implémentation de suggestions de valeurs intelligentes pour les séries (poids/répétitions) basées sur les données les plus fréquentes de la séance la plus récente du même exercice.
- **Stabilité E2E** : Atteinte de 100 % de fiabilité pour les tests de navigation sur toutes les tailles d'iPhone (Mini, 15, Max).
- **E2E Bibliothèque d'exercices** : Ajout de tests de cycle de vie complets pour la bibliothèque d'exercices (Recherche, Filtrage, Création, Modification, Suppression).
- **Trophées PR** : Intégration de retours visuels (étoile dorée) directement sur les séries atteignant un nouveau record personnel (PR).

### Modifié
- **UX Mobile** : Affinement de la sensibilité de `SwipeableRow` avec verrouillage de direction pour éviter les glissements accidentels lors du défilement vertical.
- **Mise en page mobile** : Amélioration des marges (padding) et des zones de sécurité (safe-area insets) pour garantir que les boutons d'action critiques (Terminer l'entraînement) ne soient jamais masqués par les barres de navigation.
- **Retours Inertia** : Intégration des messages flash (succès/erreur) directement dans la mise en page authentifiée via les propriétés partagées Inertia.

### Corrigé
- **Infrastructure CI** : Réparation du pipeline GitHub Actions en corrigeant les problèmes de manifeste Vite et les permissions de connexion MySQL.
- **Logique d'entraînement** : Correction des problèmes de rendu des cartes lors de l'ajout de nouveaux exercices pendant une séance active.
- **Qualité du code** : Obtention d'un score de 100/100 dans toutes les catégories PHP Insights sur la branche principale stable.

## [1.4.14] - 2026-03-02

### Ajouté
- **Dénormalisation du volume** : Ajout de `workout_volume` aux entraînements (`workouts`) et `total_volume` aux utilisateurs (`users`) pour un calcul des statistiques quasi instantané.
- **Synchronisation en temps réel** : Implémentation de la synchronisation automatisée du volume via les événements Eloquent, garantissant la cohérence des données sans surcharge lors de la lecture.

### Optimisé
- **Optimisation des stats** : Refactorisation de `StatsService` pour exploiter les données dénormalisées, réduisant le temps de requête du tableau de bord de plus de 80 %.
- **Gestion de la mémoire** : Optimisation de la commande `TrainingReminderCommand` avec un traitement par lots (chunking) et un chargement avide (eager loading) pour gérer les bases d'utilisateurs importantes.
- **Réduction de la charge utile** : Ajout de limites de sécurité aux points de terminaison de données historiques (Poids, Journal, Chronomètres) pour éviter des charges utiles JSON massives.

### Corrigé
- **Fiabilité CI** : Stabilisation définitive de GitHub Actions en basculant tous les tests sur MySQL, résolvant les échecs intermittents de migration SQLite.
- **CI : Isolation de l'environnement** : Correction de la préservation de `APP_KEY` et désactivation stricte de Telescope/Pulse dans les environnements de test pour éviter les erreurs 500.
- **CI : Harmonisation des tests** : Résolution des collisions de traits entre `RefreshDatabase` et `DatabaseMigrations` dans la suite de tests.
- **Authentification E2E** : Correction des erreurs 401 dans Dusk en activant l'API d'état Sanctum et en configurant Axios avec les identifiants.
- **Invalidation du cache** : Correction d'un bug dans le modèle `Exercise` où les clés de cache versionnées n'étaient pas correctement invalidées.
- **Robustesse Dusk** : Amélioration des sélecteurs et ajout des pauses nécessaires dans `ExerciseManagementTest` pour gérer les animations.

## [1.4.13] - 2026-02-28

### Sécurité
- **FormRequests** : Remplacement systématique de la validation en ligne des contrôleurs par des classes FormRequest dédiées pour une sécurité et une robustesse de type accrues.
- **Renforcement de l'API** : Amélioration des règles de validation pour `PushSubscription`, `WorkoutLine`, et `DailyJournal`.

## [1.4.12] - 2026-02-26

### Ajouté
- **CRUD Succès** : Implémentation du support backend complet pour la création, la lecture, la mise à jour et la suppression des succès (achievements) des utilisateurs.
- **Tests E2E** : Introduction de tests E2E complets pour les séances d'entraînement couvrant l'intégralité du flux d'entraînement.

## [1.4.11] - 2026-02-20

### Modifié
- **UI Liquid Glass** : Refactorisation de `InputLabel` et de plusieurs composants de formulaire pour adhérer strictement au système de conception Liquid Glass.

### Optimisé
- **Performance** : Optimisation des requêtes d'historique de volume et amélioration de l'indexation de la base de données pour le tableau de bord des statistiques.

## [1.4.10] - 2026-02-15

### Corrigé
- **Dépendances Frontend** : Résolution de conflits avec Inertia.js et les paquets de base de Vue 3.
- **Formatage** : Unification du style de code dans toute l'application à l'aide de Laravel Pint et Prettier.

## [1.4.9] - 2026-02-10

### Corrigé
- **Tableau de bord Pulse** : Implémentation d'un correctif architectural définitif pour les conflits de politique de sécurité du contenu (CSP) en utilisant `ConditionalCspHeaders`.
- **GitHub Actions** : Correction du label du runner ARM64 en `ubuntu-24.04-arm`, résolvant le blocage dans la CI.

### Modifié
- **Stratégie Multi-Arch** : Passage à une stratégie de build parallèle et de fusion de manifestes.

### Optimisé
- **Performance du build Docker** : Refactorisation du workflow CI pour exploiter les runners natifs ARM64, réduisant les temps de build de ~85 %.
- **Stratification du Dockerfile** : Implémentation de `--platform=$BUILDPLATFORM` pour les étapes de build et copie granulaire pour une meilleure utilisation du cache.

## [1.4.8] - 2026-02-10

### Obsolète
- Cette version contenait un label de runner GitHub Actions incorrect et une configuration CSP conflictuelle. Les utilisateurs doivent passer à la v1.4.9 immédiatement.

## [1.4.7] - 2026-02-10

### Corrigé
- **Correctif Production** : Suppression de l'option `--force` non supportée de `filament:upgrade` dans `entrypoint.sh` pour éviter un crash du serveur.

## [1.4.6] - 2026-02-10

### Ajouté
- **SyncService** : Introduction d'une logique de synchronisation centralisée pour préparer le support complet hors ligne.

### Modifié
- **Migration Axios** : Migration des interactions d'entraînement et des préférences de notification de profil vers Axios pour une communication API robuste.
- **Rector & Pint** : Application de la modernisation automatisée du code et imposition du style dans toute la base de code.

### Corrigé
- **Correctif Production** : Résolution de l'échec critique du démarrage du serveur causé par le chargement de Telescope en production.
- **Stabilité CI** : Correction des échecs de test Dusk (pages blanches) en isolant le conflit des actifs Vite.

## [1.4.5] - 2026-02-05

### Ajouté
- **Swipe-to-Action** : Intégration de `SwipeableRow` pour les séries (glisser à gauche pour supprimer, à droite pour dupliquer).
- **Chronomètre intelligent** : Ajout d'un chronomètre de repos intelligent avec retour haptique.
- **Moteur haptique** : Retour tactile pour la complétion des gestes et les événements du chronomètre.
- **Thèmes dynamiques** : Ajout d'un moteur de mode sombre/clair avec synchronisation des préférences système.

### Sécurité
- **Correction IDOR** : Prévention de l'association d'exercices non autorisée dans les objectifs/PR.
- **Assignation de masse** : Renforcement des modèles de statistiques utilisateur contre les mises à jour non autorisées.

### Modifié
- **Optimisation Bolt** : Réduction de la taille de la charge utile du tableau de bord et optimisation de l'invalidation du cache.

### Corrigé
- **Correction N+1** : Optimisation de `PersonalRecordService` pour charger à l'avance les relations entraînement/exercice (#395).
- **SetsController** : Correction de `TypeError` (#393).
- **Modal.vue** : Correction de `TypeError` dans la phase de démontage pour iOS (#394).
- **Audit Larastan** : Résolution des échecs dans le service de synchronisation des PR.

## [1.4.0] - 2026-01-30

### Ajouté
- **Offline-first** : Implémentation de la synchronisation hors ligne avec Workbox et Dexie.

### Sécurité
- **MFA** : Ajout de l'authentification multi-facteurs pour l'administration Filament.
- **CSP** : Renforcement de la politique de sécurité du contenu pour les routes du backoffice.

### Modifié
- **Ops** : Stabilisation des retours en arrière (rollbacks) de migration pour SQLite/CI.
- **PWA & Mobile** : Affinement de la sécurité mobile pour une ergonomie supérieure.

## [1.3.1] - 2026-01-24

### Corrigé
- **Notifications** : Correction de `TypeError` sur le compte des notifications mises en cache.
- **PHP 8.4** : Résolution des avertissements d'obsolescence (constantes PDO).

## [1.3.0] - 2026-01-21

### Ajouté
- **Suivi des habitudes** : Implémentation complète de la création, de la journalisation et de la visualisation des habitudes.
- **Signes vitaux** : Nouveaux modules pour le suivi de la fréquence cardiaque, de la tension artérielle et de la graisse corporelle.

### Sécurité
- **Qualité** : Atteinte de la conformité Larastan Niveau 8.

### Modifié
- **UI Liquid Glass** : Implémentation du système de conception sur toutes les pages.
- **Style** : Application d'une couverture de style Laravel Pint à 100 %.

### Optimisé
- **Performance** : Optimisation des modèles de requêtes de base de données.

### Corrigé
- **iOS Safari** : Résolution des décalages de mise en page mobile.
- **Dates** : Correction de l'alignement de l'analyse des dates entre l'API et le Frontend.

## [1.2.0] - 2026-01-15

### Ajouté
- Système de modèles d'entraînement.
- Outil de calcul de disques.

### Optimisé
- **Performance** : Optimisations (mise en cache, chargement avide).

### Sécurité
- **Renforcement** : Limitation du débit, validation des entrées.

### Modifié
- **Cache Stats** : Les statistiques du tableau de bord sont désormais mises en cache pendant 60 secondes.
- **Cache Exercices** : La liste des exercices est mise en cache pendant 10 minutes.

### Corrigé
- **AchievementService** : Correction des requêtes N+1.
- **Indexation** : Ajout des index manquants sur les colonnes fréquemment interrogées.

## [1.1.0] - 2026-01-10

### Ajouté
- Système de suivi des records personnels (PR).
- Système de succès/trophées avec célébrations.
- Compteur de série pour les jours d'entraînement consécutifs.
- Suivi des mesures corporelles.
- Fonctionnalité de journal quotidien.
- Objectifs personnalisés avec suivi de la progression.
- Notifications Web Push.

### Modifié
- **Tableau de bord** : Design repensé avec des statistiques rapides.
- **Navigation** : Amélioration de la navigation mobile.

## [1.0.0] - 2026-01-01

### Ajouté
- Sortie initiale.
- Authentification utilisateur (email + OAuth via Google, GitHub, Apple).
- Gestion des séances d'entraînement.
- Bibliothèque d'exercices avec catégories.
- Journalisation des séries et répétitions.
- Historique des entraînements.
- Statistiques de base.
- Design PWA axé sur le mobile.

[Unreleased]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.5.11...HEAD
[1.5.11]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.5.10...v1.5.11
[1.5.10]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.5.9...v1.5.10
[1.5.5]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.5.4...v1.5.5
[1.5.6]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.5.5...v1.5.6
[1.5.7]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.5.6...v1.5.7
[1.5.8]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.5.7...v1.5.8
[1.5.9]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.5.8...v1.5.9
[1.5.4]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.5.3...v1.5.4
[1.5.1]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.28...v1.5.1
[1.4.28]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.26...v1.4.28
[1.4.26]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.24...v1.4.26
[1.4.24]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.23...v1.4.24
[1.4.23]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.18...v1.4.23
[1.4.18]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.14...v1.4.18
[1.4.14]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.13...v1.4.14
[1.4.13]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.12...v1.4.13
[1.4.12]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.11...v1.4.12
[1.4.11]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.10...v1.4.11
[1.4.10]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.9...v1.4.10
[1.4.9]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.8...v1.4.9
[1.4.8]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.7...v1.4.8
[1.4.7]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.6...v1.4.7
[1.4.6]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.5...v1.4.6
[1.4.5]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.0...v1.4.5
[1.4.0]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.3.1...v1.4.0
[1.3.1]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/kuasar-mknd/gym-tracker/releases/tag/v1.0.0

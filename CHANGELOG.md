# Journal des modifications

Toutes les modifications notables de GymTracker seront documentées dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/),
et ce projet adhère au [Versionnage Sémantique](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Ajouté
- **La fin d'une séance propose d'activer les notifications de records** (#1848) : rien ne les proposait hors d'un bandeau du profil, à trois navigations de l'accueil, et un compte pouvait vivre sans que l'autorisation soit jamais demandée. Au retour d'une séance terminée, l'accueil montre une carte « Ne rate plus un record » au compte qui n'a encore aucun abonnement et n'a pas coupé ses records. « Activer » demande la permission dans le geste, comme iOS l'exige, abonne l'appareil par le chemin du profil, puis allume l'envoi push des seuls records par une écriture dédiée (`PATCH /profile/push-preferences`), limitée en débit comme les autres écritures du profil, qui ne touche ni aux rappels ni aux autres réglages : un abonnement seul n'aurait rien envoyé. « Non merci », comme une invite du système écartée ou refusée, se retient sur l'appareil et ne revient plus. La carte se tait quand le navigateur ne gère pas le push, quand la permission est déjà refusée, quand l'appareil tient déjà un abonnement transmis ou a été donné à un autre compte, et cède la place à l'invitation d'installation : une seule carte à la fois
- **Le contrôle de cohérence nocturne compte les records d'un type que plus rien n'écrit** (#1811) : l'enum des records garde quatre types hérités ('1RM', 'strength', 'cardio', 'volume') qu'aucun code n'écrit, faute de savoir si la production en porte encore, et les retirer alors qu'une ligne les porte ferait tomber en erreur 500 le tableau de bord du compte. `app:verify-data-coherence` nomme désormais chaque type hors des trois suivis, avec son nombre de records et de comptes, et sort en échec, ce qui se lit dans « Tâches planifiées » et sur la page de santé. Il décrit les types et non les lignes, pour qu'un type rare ne disparaisse pas derrière la limite d'exemples ; il lit la colonne brute, et verra donc encore ces lignes après le retrait des cas ; et il compare octet par octet, comme l'enum, alors que la colonne, insensible à la casse et aux espaces finales, tient 'MAX_WEIGHT' ou 'max_weight ' pour 'max_weight', deux valeurs que le modèle ne sait pourtant pas relire. La fabrique de tests, qui en écrivait deux au hasard, ne crée plus que des records de poids maximal, et la liste des trois types suivis ne s'écrit plus qu'à un endroit, sur l'enum
- **Une valeur dérivée stockée n'acquiert plus d'écrivain sans qu'on le décide** (#1513) : volumes de séance, série de jours, avancement des objectifs, records, succès, propriétaire recopié sur les lignes, les séries et les journaux d'habitude, date recopiée sur les lignes de séance, doses restantes et valeurs proposées en cache sont stockés puis entretenus depuis plusieurs endroits, quand la refonte prévue n'en veut qu'un par valeur. En attendant, `LesProjectionsGardentLeursEcrivainsTest` fige les écrivains de chacune (fichier et méthode) et nomme comme dette les huit qui en ont plusieurs ; il échoue quand un nouvel endroit écrit l'une de ces valeurs, quand un écrivain listé ne l'écrit plus, ou quand une statistique en cache gagne un second `Cache::remember()`. Il lit le code sans l'exécuter : son docbloc dit ce qu'il ne voit pas (nom de colonne calculé, cascades de la base, code hors de app/ et de routes/). La règle est dans `.ai/rules/models.md`.
- **Un en-tête `Server-Timing` mesure le temps serveur d'une page, à la demande** (#1315) : rien dans l'application ne comptait de durée, et la seule mesure possible était un `curl` depuis l'extérieur, réseau compris. Allumé par `SERVER_TIMING_ENABLED` (coupé par défaut, transmis par la composition avec `false`), il donne la durée de l'application, découpée en routage, contrôleur et rendu, et le nombre et la durée cumulée des requêtes SQL, lisibles dans l'onglet réseau du navigateur. Seulement pour un utilisateur connecté ou un administrateur du panneau : jamais pour un invité, ni sur une réponse d'authentification ou un 404, où une durée dirait si un compte ou une ressource existe. Ses écouteurs se posent une fois au démarrage, et sa mesure ne survit pas à la requête, Octane compris ; environ 120 octets, sous le budget d'en-têtes.

### Corrigé
- **Le crochet de commit formate le PHP sans dépendre du PHP de l'hôte** (#1928) : lint-staged lançait `./vendor/bin/pint` avec le PHP de l'hôte, et Pint lit chaque fichier avec le PHP qui le lance. Sur un poste resté en PHP 8.3, il échouait en erreur de syntaxe sur le code du projet et refusait tout commit. La règle passe désormais par `scripts/formater-le-php.sh`, qui lance Pint dans le conteneur de Sail quand il tourne pour ce dossier (Compose nomme le projet d'après le nom du dossier : le conteneur d'une autre copie du même nom, que Pint aurait formatée à la place, est écarté), en ramenant les chemins à la racine du dépôt, sinon avec le PHP de l'hôte, ou celui de `PINT_PHP`, s'il atteint la contrainte `php` de `composer.json`. Sans l'un ni l'autre, le commit est refusé avec la marche à suivre, jamais accepté sans formatage. `LeCrochetDeCommitFormateAvecLePhpDuProjetTest` refuse le retour de `./vendor/bin/pint` dans lint-staged et exécute le script contre un faux `docker` et un faux PHP ; il exige aussi qu'un échec de Pint, dans le conteneur comme avec le PHP de l'hôte, refuse le commit
- **Une séance ne change plus de propriétaire dans le back-office** (#1933) : la page de modification d'une séance laissait choisir un autre compte, mais ses lignes et ses séries, qui recopient le propriétaire, restaient à l'ancien, et ni la série de jours ni les records des deux comptes n'étaient recalculés ; toute lecture filtrée par propriétaire voyait une séance de l'un dont les séries étaient à l'autre. Le propriétaire se choisit toujours à la création. À la modification, le champ reste affiché mais désactivé, la page ne l'écrit plus, et une requête Livewire forgée qui en change la valeur enregistre le reste du formulaire, mais pas le propriétaire, que ce soit par la page de modification ou par l'action de modification de la table, qui partage son formulaire. En dernier recours, le modèle lève une exception quand un enregistrement tente de changer le propriétaire d'une séance existante (seuls les chemins qui sautent les événements, `saveQuietly()`, `withoutEvents()` et le constructeur de requêtes, lui échappent). Une séance réattribuée avant ce correctif reste incohérente, et le panneau ne peut plus la rendre : le contrôle de cohérence nocturne (`app:verify-data-coherence`) compare donc désormais le propriétaire recopié sur les lignes et les séries à celui de leur séance, copie vide comprise, et nomme chaque séance en écart. `--repair` ne la corrige pas : il faudra recopier le propriétaire et refaire la série de jours et les records des deux comptes, le travail d'une action dédiée
- **Le journal d'audit se purge enfin en production** : la tâche planifiée `activitylog:clean --days=180` demande confirmation en production et, lancée chaque nuit par le planificateur sans personne pour répondre, s'annulait (« APPLICATION IN PRODUCTION. Command cancelled. », code 1) : la table n'a jamais été purgée, et chaque passage enregistrait une exception. Elle porte désormais `--force`, et une garde exige `--force` de toute tâche planifiée dont la commande demande confirmation
- **Les outils du back-office s'ouvrent en production à l'administrateur qui en voit le lien** : le lecteur de journaux donnait sa porte au démarrage à un service qu'Octane oublie après chaque requête, si bien que seule la première requête d'un worker passait et que les suivantes, page et API, répondaient 403 à tous, alors que hors production il s'ouvrait à tout administrateur du panneau ; sa porte est désormais `viewLogViewer` (capacité `view-logs`), dans le Gate. Pulse répondait 403 au super administrateur et cachait son lien, sa porte n'étant ouverte qu'au poste de développement, ce qu'une porte de test toujours vraie masquait : `viewPulse` rejoint les capacités du super administrateur, Pulse passe par l'authentification du panneau, et ses balises sont signées à la compilation de ses gabarits, au lieu d'un middleware qui réécrivait la réponse et cassait livewire.js. Le lien « Horizon » menait à un 403 : Horizon admet aussi l'administrateur du panneau qui a `view-outils`, depuis une adresse de `ADMIN_ALLOWED_IPS`, la voie de `HORIZON_ALLOWED_EMAILS` ne changeant pas. Comme le panneau, Pulse, le lecteur de journaux et cette voie d'Horizon refusent une session que le changement du mot de passe de l'administrateur a invalidée : une session volée n'y rouvre plus rien. Le détail d'une exception colore de nouveau sa pile sous la CSP de production : son script, écrit en PHP par le paquet, est signé comme les autres. Une garde ouvre chaque lien du menu du super administrateur en production, Octane simulé, et une autre refuse qu'un service `scoped` soit configuré au démarrage
- **L'icône de l'application est un PNG que l'iPhone accepte, aux couleurs de la charte** (#1850) : iOS refuse un SVG en `apple-touch-icon`, et le logo n'existait qu'en SVG ; l'écran d'accueil montrait donc une capture de la page. Il existe désormais en PNG opaque de 180 px pour iOS, en 64, 192 et 512 px pour le manifeste, et en icône masquable distincte : le manifeste ne déclare plus une seule image en « any maskable », la même pour l'icône ordinaire et pour celle que le lanceur Android découpe dans son masque. Le logo quitte le bleu nuit du thème retiré pour le couple de l'utilitaire `accent-fill`, fond orange et tracés blancs. `favicon.ico`, vide jusqu'ici, porte l'icône, y compris dans le back-office, et le badge des notifications, demandé sous `/badge.svg`, un fichier qui n'a jamais existé, est un PNG blanc sur transparent : Android n'en lit que la transparence. Les PNG sont versionnés ; `pwa-assets.config.js` dit comment les refaire. iOS fige l'icône au moment de l'ajout : sur un iPhone où l'application est déjà installée, il faut sans doute la retirer de l'écran d'accueil puis l'y remettre
- **Un abonnement push que le navigateur remplace ou révoque se répare seul** (#1847) : le service worker n'écoutait pas `pushsubscriptionchange`, et le profil ne rendait l'abonnement du navigateur au serveur que si le compte n'en avait aucun. Un appareil dont l'abonnement était renouvelé, ou révoqué par WebKit, cessait donc de recevoir pendant que le serveur écrivait vers une adresse morte. Le worker enregistre désormais le nouvel abonnement et fait oublier l'ancien, sans jeton CSRF : l'en-tête `Sec-Fetch-Site: same-origin`, que Laravel vérifie déjà sur toutes les routes web, l'autorise. Et comme iOS n'émet pas l'évènement, chaque ouverture de l'application, sur n'importe quelle page, compare l'abonnement du navigateur à ce que l'appareil a transmis, sans aucune écriture quand rien n'a changé. Sur un téléphone partagé, l'abonnement reste au compte qui l'a activé : un autre compte qui s'y connecte voit le bandeau « Activer » plutôt que de recevoir, en silence, ses notifications sur l'écran du propriétaire
- **La connexion par Google et GitHub peut s'activer en production** (#1908) : leurs identifiants n'atteignaient aucun conteneur, et l'URL de rappel n'avait pas de défaut — sans elle, Google refuse l'échange. Les identifiants sont transmis au seul conteneur `app`, l'URL de rappel se déduit d'`APP_URL` (y compris quand la pile transmet une variable vide), et un échange refusé par le fournisseur laisse enfin une trace dans les journaux. Apple demande davantage (rappel en POST, secret signé) et reste masqué (#1911)
- **Les journaux de worker et scheduler se lisent enfin, et la production n'écrit plus tout dès debug** (#1907) : worker et scheduler journalisaient dans un fichier de leur propre conteneur, ni monté ni affiché, et app sur stderr seul — or le planificateur envoie la sortie de chaque tâche dans /dev/null. Les trois conteneurs écrivent désormais dans `docker logs` et dans un fichier par conteneur sur le volume `journaux`, que lit la page « Journaux » du panneau ; `LOG_LEVEL` vaut `info` par défaut
- **Une mise à jour de la pile met à jour les trois conteneurs** (#1813) : un conteneur garde l'image avec laquelle il a été créé, et le scheduler de production a tourné des semaines sur une ancienne version. `pull_policy: always` retélécharge l'image de app, worker et scheduler à chaque mise à jour
- **Le fichier de production porte les réglages de la pile déployée** (#1668) : Pulse coupé par défaut (`PULSE_ENABLED`, réglable) et MySQL réglé pour le disque de production (`innodb-redo-log-capacity` 256M, `innodb-io-capacity` 200, `-max` 1000), appliqués et mesurés en production le 03/09 mais absents du dépôt : réaligner la pile sur ce fichier les aurait perdus
- **L'application installée s'ouvre de nouveau après quelques semaines d'absence** (#1902) : elle tombait sur la page d'erreur du proxy inverse, et `/login` y menait aussi. Ce n'étaient pas les cookies. Le proxy inverse lit les en-têtes d'une réponse dans 4 Kio, et l'en-tête `Link` qui recopiait chaque morceau préchargé en portait à lui seul 2,3 Kio : un accueil chargé en entier dépassait la limite et repartait en 502. Une navigation dans l'application ne porte pas cet en-tête, d'où une panne visible seulement à l'ouverture, quand « Se souvenir de moi » connecte directement — et un effacement des cookies qui semblait réparer. L'en-tête disparaît, les mêmes indications restent dans la page, et chaque page complète repasse à 2 Kio d'en-têtes
- **Un abonnement push venu de Windows s'enregistre** (#1903) : la colonne des adresses d'abonnement était restée à 500 caractères, quand webpush la porte à 1 024 depuis sa 12.1 — la migration qui l'accompagnait n'avait jamais été publiée. Une adresse WNS plus longue partait en erreur 500 ; elle est désormais enregistrée, et une adresse qui dépasserait encore la colonne est refusée proprement
- **`HORIZON_ALLOWED_EMAILS` et `ADMIN_INITIAL_PASSWORD` atteignent enfin la production** (#1906) : le README les présentait comme des variables du déploiement, mais `docker-compose.prod.yml` ne les transmettait à aucun conteneur. Posée dans la pile, la première laissait Horizon fermé à tout le monde, et le seeder du premier administrateur échouait faute de la seconde. `MAIL_ENCRYPTION`, transmise et lue par rien, cède la place à `MAIL_SCHEME`, que Laravel lit ; vide, le schéma se déduit toujours du port, rien ne change pour une pile existante. `FRANKENPHP_WAIT_FOR_WORKER`, que ni Octane ni FrankenPHP ne lisent, disparaît
- **`.env.example` démarre Sail tel quel** (#1906) : il visait une base SQLite absente, et son bloc MySQL nommait `root` comme utilisateur, que l'image mysql refuse — le conteneur ne démarrait pas, et les tests attendaient de toute façon `sail`. Il vise désormais les services `mysql` et `redis` de Sail, rend `APP_DEBUG` au développement et nomme l'application GymTracker plutôt que Laravel ; les réglages AWS, memcached et de diffusion, qu'aucun code n'emploie, en sortent
- **Un Sail neuf lance les tests** (#1906) : `artisan test -p` se connecte à `gym_tracker_testing` pour créer la base de chaque processus, et aucun script de Sail ne la créait — la suite échouait dès le premier test. Le script d'initialisation de MySQL la crée et l'ouvre à l'utilisateur `sail`. `npx vitest run` ramassait de son côté les specs que Livewire, Ziggy et PHPStan livrent dans vendor/, et échouait en local ; vendor/ est exclu comme node_modules/
- **Les PR Composer et Docker de Dependabot portent enfin leurs labels** (#1905) : `.github/dependabot.yml` demandait `php`, `composer` et `docker`, qui n'existaient pas sur le dépôt. Dependabot ne crée aucun label : il les omettait, et le disait dans un commentaire sur chaque PR que personne ne lisait. Le workflow `.github/workflows/labels-dependabot.yml` crée désormais tout label que le fichier demande et qui manque, dès que le fichier change sur `main` ou à la demande, sans jamais réécrire la couleur ni la description d'un label existant. Un label présent sous une autre casse (« PHP » pour « php ») fait échouer le run en nommant le renommage à faire : Dependabot compare les noms exactement et l'omettrait encore, et GitHub refuse d'en créer le doublon. Une liste vide, signe d'une expression yq qui ne lit plus le fichier, le fait échouer aussi. `LesLabelsDeDependabotExistentTest` exécute le script contre un faux `gh`, et tient son déclencheur, l'expression qui lit la liste, l'absence de `--force` et ses droits, limités à `issues: write`
- **Un label de Dependabot supprimé ou renommé depuis l'interface ne manque plus jusqu'à la prochaine modification de `dependabot.yml`** (#1905) : `.github/workflows/labels-dependabot.yml` ne tournait que lorsque le fichier changeait sur `main`. Un label supprimé ou renommé à la main ne changeait aucun fichier, et Dependabot recommençait à l'omettre en silence. Le workflow tourne désormais aussi à chaque suppression ou renommage de label : il recrée aussitôt celui qui manque, et un simple changement de casse (« PHP ») fait échouer un run visible. `LesLabelsDeDependabotExistentTest` exécute le script sur des listes écrites dans le test, et non plus sur celle de `dependabot.yml` : y ajouter ou en retirer un label ne fait plus échouer la garde
- **Les parcours navigateur ne peuvent plus vider la base de développement** (#1909) : `artisan dusk` lance Pest avec le `.env`, devant lequel les réglages de `phpunit.dusk.xml` s'effaçaient — `force="true"` n'y aurait rien changé, Laravel lit `$_SERVER` d'abord. Sous Sail, les parcours visaient donc la base de développement, et les quatorze classes qui portent `DatabaseTruncation` la vidaient au premier test (`migrate:fresh`) ; le navigateur, dans le conteneur `selenium`, cherchait l'application sur `localhost`. Chaque parcours juge désormais sa configuration avant toute écriture : une base dont le nom ne finit pas par `_dusk`, ou une `APP_URL` locale quand le navigateur tourne ailleurs, l'arrêtent avec la marche à suivre. Le MySQL de Sail crée `gym_tracker_dusk`, le README donne la recette de `.env.dusk.local` et la commande qui crée la base sur un volume existant, et `phpunit.dusk.xml` cesse d'afficher une base, un hôte et une URL qu'il ne réglait pas

### Sécurité
- **Changer son mot de passe ferme les autres sessions du compte** (#1940) : le panneau le faisait déjà, pas le reste de l'application. Une session volée, un cookie copié sur un appareil partagé ou perdu, survivait au changement du mot de passe, qui est justement le geste de la personne qui s'en inquiète, et ouvrait encore Horizon au compte listé dans `HORIZON_ALLOWED_EMAILS`. Chaque page vérifie désormais que la session porte l'empreinte du mot de passe actuel : quand il change, depuis le profil, par la réinitialisation par courriel ou depuis le panneau, les autres sessions sont renvoyées à la connexion à leur requête suivante, et l'API de la page de séance leur répond 401. La session qui change le mot de passe reste ouverte, sous un nouvel identifiant (une copie de son cookie ne l'ouvre plus), et garde « se souvenir de moi », que le changement lui faisait perdre à l'expiration de la session. Changer le coût du hachage (`BCRYPT_ROUNDS`) fermera de même les autres sessions de chaque compte à sa connexion suivante
- **La page Sauvegardes ne s'ouvre plus qu'à qui peut faire quelque chose d'une sauvegarde** : le greffon n'avait pas de porte, et tout administrateur entré au panneau voyait la page et son lien — la liste des archives de la base, leurs dates et leurs tailles —, même sans aucune capacité de sauvegarde. Elle exige désormais `create-backup`, `download-backup` ou `delete-backup`, ce que le super administrateur a
- **Se déconnecter détache l'appareil des notifications du compte** (#1926) : la déconnexion ne retirait aucun abonnement push, et l'appareil continuait de recevoir les records, les rappels et les succès du compte parti, sur l'écran verrouillé ; sur un appareil partagé, la personne suivante les voyait. Le menu du compte, le profil et la page de vérification de l'adresse passent désormais par une seule fonction, qui fait oublier au serveur l'adresse de cet appareil, le désabonne dans le navigateur et efface le mémo de l'appareil avant d'envoyer la déconnexion. Le compte suivant doit donc activer lui-même les notifications. Rien ne retient la déconnexion : chaque étape avale son échec, et passé 600 ms elle part quand même, l'oubli en cours abandonné et le désabonnement achevé après coup. La suppression du compte détache aussi l'appareil, une fois la suppression acceptée. Le serveur n'oublie que les adresses du compte connecté, ce qu'un test tient désormais
- **Supprimer son compte efface aussi ses notifications, ses abonnements push, ses jetons et son historique** (#1935) : les tables liées au compte par `user_id` suivaient par cascade, mais six le désignent par une relation polymorphe, sans clé étrangère, et restaient en base — le contenu des notifications, l'adresse et les clés de chaque appareil abonné (vers lesquels un envoi visant cet identifiant serait parti), les jetons d'API, les rôles et permissions, et les entrées du journal d'activité dont le compte était la cause ou le sujet. `User::delete()` les efface désormais dans la même transaction que le compte, par chacun des chemins (profil, page d'édition et suppression groupée du panneau, `User::destroy()`, `deleteQuietly()`), en filtrant toujours sur le type et l'identifiant : l'administrateur qui porte le même identifiant garde ses rôles et son audit. L'effacement prime sur l'audit pour un compte qui demande sa suppression. Une garde exige que toute nouvelle colonne polymorphe de la base soit effacée avec le compte ou écartée avec sa raison, et la page de profil ne promet plus un effacement « définitif » que les sauvegardes de la base démentent jusqu'à leur expiration
- **Le dépôt ne nomme plus l'infrastructure de production** : la documentation, les commentaires, les tests et les règles citaient le matériel, le système, les outils et le réseau privé qui font tourner la production, et jusqu'à une adresse de ce réseau. Dans un dépôt public, chacun de ces noms dit à un attaquant quoi essayer. Ils sont remplacés par ce que fait la production (proxy inverse, pile, disque de production), les adresses d'exemple passent aux plages de documentation, et `LInfrastructureNeSeNommePasTest` refuse leur retour sans les écrire lui-même (empreintes seulement)
- **Le nonce CSP change à chaque requête** (#1904) : il était tiré une fois, au démarrage de l'application, et sous Octane chaque worker servait donc le même nonce à tous les utilisateurs pendant ses 500 requêtes. Ce nonce est la seule barrière de `script-src` contre un script injecté en ligne : lu dans le code source de n'importe quelle page, il ouvrait toutes les autres. Un middleware en tête de la pile globale le tire désormais à chaque requête, pour l'application, le panneau et Pulse. Le script en ligne de Horizon, qui n'en portait aucun, le reçoit aussi, mais son tableau de bord reste vide en production : son Vue compile le gabarit de la page avec `new Function`, et la CSP ne permet `'unsafe-eval'` que sur le panneau et Pulse — l'ouvrir à Horizon reste à décider à part. La meta `csp-nonce` le porte dans son attribut `nonce`, celui que lit Vite, et non plus dans `content`
- **Le panneau d'administration envoie enfin une Content-Security-Policy** (#1920) : sa pile ne passait pas par le groupe `web`, et la partie la plus sensible de l'application répondait sans aucune CSP — le nonce de ses scripts ne protégeait rien. Il reçoit désormais celle de l'application. Filament écrit pourtant dans ses gabarits des scripts en ligne sans nonce (thème forcé, état replié des groupes du menu, alerte de modifications non enregistrées, notifications), que cette CSP aurait bloqués : un précompilateur Blade, `SigneLesScriptsEnLigneDesPaquets`, les signe à la compilation de ces gabarits, jamais sur la page rendue, pour ne pas signer un script qu'une donnée injectée y aurait glissé. Les en-têtes du panneau, CSP comprise, tiennent sous le budget de 3 Kio. Vérifié dans Chromium sous la CSP de production : aucune violation sur les seize pages du menu
- **Horizon s'affiche en production** (#1921) : son Vue compile le gabarit de la page avec `new Function`, et la CSP de production ne lui permettait pas `'unsafe-eval'` — le tableau de bord restait blanc. Son chemin le reçoit, comme Pulse et le panneau, et lui seul : ni l'application ni un chemin voisin au même préfixe
- **La page « Journaux » s'affiche en production** (#1922) : le lecteur de journaux servait un script en ligne sans nonce, que la CSP bloquait ; `window.LogViewer` restait indéfini et la page vide. Son gabarit est signé à la compilation, comme ceux de Filament
- **`brace-expansion` passe en 5.0.12 et `fast-uri` en 3.1.8** (#1902) : trois avis publiés le 29/09 visent `brace-expansion` 5.0.9 — deux dénis de service par récursion non bornée (CVSS 7,5) et un par expansion quadratique (5,3) —, un quatrième la normalisation des hôtes de `fast-uri` 3.1.7 (4,8). Les deux ne servent qu'à la construction (eslint, workbox-build), mais l'audit OSV de la CI refusait toute PR

### Modifié
- **Toutes les dépendances sont à jour, majeures comprises** (#1903) : Laravel 13.34, Filament 5.9, inertia-laravel 3.5 avec @inertiajs/vue3 3.8, Livewire 4.4.7, Vite 8.3.2, Vitest 5.0.3, larastan 3.12 et phpstan 2.2.16, Dusk 8.7, et trois majeures : webpush 13, socialiteproviders/apple 6 (le nonce de la connexion Apple est désormais vérifié au retour) et Pest 5 avec PHPUnit 13. Le greffon de sauvegarde du panneau suivait une branche de développement, qui venait d'y amener sa version 4 et de casser le démarrage du panneau : il est figé sur `^4.0`. Une dépendance se met désormais à jour sans attendre d'accord, majeures comprises (`.ai/rules/dependencies.md`)
- **Le README documente toutes les variables d'environnement** (#1906) : il n'en présentait que sept, dont deux sans effet, et ignorait celles sans lesquelles la pile ne démarre pas ou n'envoie rien (`APP_KEY`, `DB_*`, `DB_ROOT_PASSWORD`, `REDIS_PASSWORD`, `MAIL_*`). La nouvelle section dit, pour la production, ce qu'il faut poser dans la pile, ce que la composition fixe et ce qu'elle ne transmet pas ; pour le développement, chaque entrée du gabarit ; et les secrets de la CI. `LeReadmeDocumenteLesVariablesTest` refuse une variable de la composition, du gabarit ou un secret de CI que le README ne nomme pas, et une variable présentée comme réglable en production que la composition ne transmet pas. Le reste du README est remis d'aplomb : nombres de tests, ce que la publication d'un tag exige vraiment (l'image n'attend ni semgrep, ni secrets, ni workflows, mais attend le démarrage sur une base vide) et comment la débloquer, menu aux ancres mortes, fonctionnalités qui n'existent pas (tension, fréquence cardiaque) ou manquaient (outils), pile technique, seuils de mutation et de PHP Insights. Le README se tient désormais à jour dans la PR qui le rend faux (`.ai/rules/readme.md`)

### Retiré
- **Trois réglages morts de la configuration des parcours navigateur** (#1927) : `DuskTestCase::prepare()`, censée démarrer ChromeDriver hors Sail et poser `APP_ENV=testing`, ne tenait plus qu'à une étiquette de docblock que PHPUnit ignore depuis sa version 12, et rien d'autre ne l'appelait : elle a perdu son attribut `#[BeforeClass]` le 02/03/2026, dans le commit même qui lui ajoutait `APP_ENV=testing`, pose qui n'a donc jamais eu lieu ; la liaison de `DatabaseTruncation` au dossier tests/Browser dans `tests/Pest.php` n'atteint que les fichiers Pest, et il n'y en a plus là depuis le 06/03/2026, quand les trois derniers ont été réécrits en classes ; la connexion `mysql_dusk` de `config/database.php` n'a jamais été lue par rien. Aucun n'agissait plus : la CI démarre son ChromeDriver dans une étape à part, Sail fournit Selenium, et les quatorze parcours qui vident leur base portent eux-mêmes le trait. Les réveiller aurait doublé le pilote de la CI et posé `APP_ENV=testing` dans le processus des parcours sous Sail, qui garde `local` à dessein : ils sont retirés. `LesCrochetsDesTestsSontLusTest` refuse désormais, dans tests/, une étiquette de docblock qui porte le nom d'un attribut de PHPUnit, et dans `tests/Pest.php` une liaison vers un dossier sans test Pest ; elle refuse aussi les formes connues du réveil de `prepare()` sous un attribut que PHPUnit lit : un démarrage de ChromeDriver depuis tests/, son binaire nommé dans les parcours, une pose d'`APP_ENV` dans les parcours, chaîne interpolée, heredoc et `??=` compris

### Ajouté
- **La page « Santé » dit quand le dossier des sauvegardes n'est pas inscriptible** (#1812) : le dossier de l'hôte monté pour les sauvegardes refusait l'écriture au conteneur, et rien d'autre que « Backups », vingt-six heures plus tard et sans dire pourquoi, ne le signalait. Le contrôle « Dossier des sauvegardes » écrit puis efface une sonde toutes les cinq minutes, et chaque conteneur fait la même vérification à son démarrage : il avertit dans `docker logs`, avec le chemin, l'uid et la marche à suivre, sans jamais s'arrêter. Chaque accès au partage est borné à dix secondes, au démarrage comme dans le contrôle : un partage réseau qui ne répond plus ne rend pas d'erreur, il fait attendre sans fin, et aurait figé `health:check` dans le planificateur — ou un travailleur d'Octane au rafraîchissement de la page. Le contrôle passe alors au rouge « Sans réponse », et « Backups », dont le parcours des archives n'a pas de délai, attend que le dossier réponde
- **La page « Santé » dit quand un conteneur n'exécute pas la dernière image** (#1813) : un conteneur garde l'image avec laquelle il a été créé, et le scheduler de production a tourné des semaines sur une ancienne version. L'image connaît désormais sa version et son commit (`APP_VERSION`, `APP_REVISION`, posées par la CI), chaque conteneur les annonce dans le cache à son démarrage, et le contrôle « Versions des conteneurs » passe au rouge quand app, worker et scheduler diffèrent depuis plus de dix minutes, en nommant le conteneur en retard ; à l'orange quand l'un d'eux ne s'est jamais annoncé
- **La page « Santé » relit les réglages de MySQL en production** (#1668) : `innodb_flush_log_at_trx_commit` à 1 ou un journal binaire actif la mettent au rouge — chaque écriture repaierait 250 à 500 ms sur le disque de production —, Pulse actif ou un réglage que MySQL ne rend pas à l'orange. Lecture seule, et rien n'est jugé hors production, où Sail et la CI gardent les défauts de MySQL

## [1.5.19] - 2026-09-07

### Retiré
- **Les derniers restes du mode sombre** (#1806) : il n'y en aura pas, la décision est prise et l'issue fermée. Partent avec elle un thème sombre complet resté dans la page de la charte — sur la page même qui explique pourquoi il n'y en a plus —, les vues d'un paquet désinstallé en #1673, un en-tête de bloc CSS dont le corps était parti, un emplacement de sélecteur de thème vide dans le profil, et une clef `gymtracker-theme` qu'un test écrivait et que plus personne ne lisait

### Corrigé
- **Les notifications push arrivent enfin sur iPhone** (#1849) : deux causes se cumulaient. L'autorisation n'était jamais demandée, parce que le serveur répondait « déjà abonné » dès qu'un abonnement existait quelque part sur le compte — un abonnement pris sur un autre navigateur masquait donc le bandeau « Activer » sur le téléphone, seul endroit de l'application qui réclame l'autorisation, et le rattrapage prévu avalait ses échecs en silence. Et rien n'arrivait de toute façon : le service worker vérifiait `self.Notification.permission`, une interface que WebKit n'expose pas dans la portée d'un worker, si bien que chaque push repartait sans rien afficher — ce qui fait révoquer l'abonnement par iOS, la panne s'aggravant d'elle-même
- **Une notification touchée ouvre la page qu'elle annonce** : le worker cherchait sa destination dans un champ que le serveur n'a jamais envoyé, donc tout clic ouvrait l'accueil. Le clic reprend aussi la fenêtre ouverte au lieu d'empiler un second exemplaire de l'application
- **Un envoi push refusé laisse une trace** : le canal émet bien l'événement, personne ne l'écoutait, et un appareil pouvait cesser de recevoir pendant des semaines sans qu'aucun journal ne le dise
- **L'application installée n'ouvre plus sur un écran bleu nuit** : le manifeste PWA annonçait `#0f172a` en fond et en couleur de thème, la couleur du thème retiré, alors que la page déclare `#f8faff` deux lignes plus loin
- **Les contrôles de formulaire ne sortent plus en sombre sur un téléphone réglé en sombre** : sans `color-scheme: light`, le navigateur repeint lui-même cases à cocher, listes déroulantes, ascenseurs et champs de saisie
- **Le back-office et le lecteur de journaux suivaient le réglage du système** : Filament et log-viewer activent leur mode sombre par défaut, ce qui rendait au panneau le comportement « reçu sans l'avoir demandé » qui avait justement fait retirer le mode sombre de l'application

### Modifié
- **La garde du mode sombre voit enfin ce qu'elle prétendait tenir** : elle ne cherchait que les variantes `dark:` et la bascule Tailwind, d'où le thème sombre resté des mois dans la charte sous une CI verte. Elle refuse désormais aussi `prefers-color-scheme: dark` et `[data-theme="dark"]`, et exige la déclaration `color-scheme: light`

## [1.5.18] - 2026-09-07

### Ajouté
- **Une invitation à installer l'application** (#1805) : elle est une PWA complète depuis longtemps et rien ne le disait. Sur Android, le bandeau propose l'installation ; sur iPhone, où le navigateur n'offre rien, il donne le geste — Partager, puis « Sur l'écran d'accueil ». Il ne revient pas après un refus, et ne s'affiche jamais dans une application déjà installée
- **Une page « Raccourcis »** dans « Plus », et `?` pour l'ouvrir de n'importe où (#1817) : l'application n'en avait qu'un, ⌘K dans la bibliothèque, annoncé par une pastille et écrit nulle part. ⌘/Ctrl + Entrée ajoute une série au dernier exercice pendant une séance
- **Un seul format par grandeur** (#1787) : `Utils/nombre.js` écrit les poids, volumes, variations et pourcentages en suisse romand — virgule décimale, apostrophe aux milliers. « 78.40 kg » et « 15,750 kg » disparaissent ; une garde interdit `toFixed(` et `toLocaleString(` ailleurs
- **Un compte neuf reçoit le jeu de plaques olympique** (#1799) : 25, 20, 15, 10, 5, 2,5 et 1,25 kg par paire, au lieu d'un calculateur qui ouvrait sur « Impossible de charger ce poids »
- **Six composants de la charte** (#1781, #1786, #1792, #1794) : `GlassSegmented` (choix exclusif, flèches au clavier), `GlassChip` (pilule de filtre, cible de 44 px même en petit), `GlassTile` (tuile de choix ou d'action), `GlassTextarea` (étiquette, compteur, erreur, hauteur qui suit le texte), `GlassStat` (chiffre, unité, libellé, tendance) et `GlassIcon` (sept tailles, remplissage, nom pour les lecteurs d'écran)

### Modifié
- **Vitest passe en 5** : le paquet de couverture doit monter avec lui, d'où une seule montée pour les deux — séparées, chacune échoue sur l'autre. Vitest 5 vide l'historique des espions avant chaque test, ce qui faisait échouer trois assertions sur ce qu'un module avait fait en s'important
- **L'installation de l'application télécharge 572 Kio au lieu de 1 249** (#1814) : le service worker préchargeait 142 fichiers, dont le graphique et le morceau de chaque page jamais ouverte. Il ne garde que la coquille — l'entrée, la feuille de style, Vue, les polices latines, l'accueil et la séance en cours ; le reste entre au cache à la première visite. Un contrôle de la CI refuse un precache qui regrossit
- **Trois tailles de titre, et rien d'autre** (#1783) : les `h1`–`h3` portaient 28 combinaisons de classes, et le titre d'une carte s'écrivait tantôt en capitales italiques, tantôt en casse de phrase, d'un écran à l'autre. `titre-page`, `titre-section`, `titre-carte` et `sur-titre` tiennent la charte, et une garde refuse tout titre qui compose sa propre typographie
- **`axios` quitte le paquet** (#1815) : Inertia 3 s'en passe, et il ne restait que pour quatre fichiers tout en partant dans le morceau principal de chaque page. `Utils/http.js` rend la même forme d'appel et la même forme d'erreur — la file hors-ligne garde ses requêtes en attente d'une version à l'autre. Le morceau principal passe de 80 à 31 Ko
- **Le bureau ne s'étire plus** (#1802, #1817) : les huit outils et la page d'une séance gardent une colonne au lieu des 1 280 px du conteneur, et les champs d'une série s'arrêtent à 128 px au lieu de 470
- **L'en-tête des jours suit la liste des habitudes** (#1807) : collé sous l'en-tête de l'application, il reste au-dessus des cases qu'il nomme
- **Une seule façon de dire « il n'y a rien »** (#1791) : trente écrans écrivaient la leur — carte complète, icône et deux lignes, ou phrase grise seule. `GlassEmptyState` prend une taille `ligne` pour l'intérieur d'une carte déjà titrée, et une garde Vitest refuse le prochain « Aucun… » écrit à la main
- **Les vingt écrans qui restaient écrivent leurs chiffres comme le reste** (#1787) : plaques, échauffement, hydratation, macros, Wilks, 1RM, objectifs, mensurations, cartes de stats et de l'accueil. Le pourcentage prend son espace insécable (« 56 % »), le millier son apostrophe (« 1'000 ml »)
- **Les graphiques comptent dans la langue de l'application** : Chart.js graduait ses axes d'après celle du navigateur, donc « 15,750 » en anglais à côté de « 15'750 ». L'unité quitte le nom des séries pour la valeur : « Volume : 15'750 kg » au lieu de « Volume (kg) : 15'750 kg »
- **Les axes qui comptent n'ont plus de décimale** (#1800) : la fréquence des séances, les répétitions et les séries se graduent en entiers
- **Tout ce qui se clique se voit au clavier et se vise au doigt** (#1782, #1795) : anneau de focus sur les treize boutons qui n'en avaient pas, 44 px pour la petite taille de bouton, la bascule d'échauffement d'un modèle et les pastilles de couleur d'une habitude
- **L'action principale d'un formulaire prend la largeur du téléphone** (#1793) : profil, mot de passe, notifications, journal, habitude et objectif ; « Annuler » passe sous elle au lieu de la serrer
- **Un seul dessin pour « ajouter », « réessayer » et « fermer »** : la modale de célébration, la carte de progression, le formulaire de modèle, le journal et l'arrondi de l'échauffement passent par les composants de la charte
- **Un seul titre par page** (#1789) : sur les six outils qui ont un grand titre dans la page, l'en-tête ne garde que la flèche et les actions ; « Minuteur », « Compléments » et « Modèle » remplacent trois titres qui se coupaient ; l'en-tête descend d'un cran au-delà de douze caractères
- **« Poids » et « Mensurations »** au lieu de deux pages nommées « Mesures » (#1790)
- **La barre basse dit ses onglets** : Accueil, Stats, Séances, Plus sous les icônes ; le « + » de l'en-tête des séances, qui doublait le bouton central, s'en va (#1796)
- **La bannière de séance active tient sur une ligne** hors accueil et liste des séances (#1797), et ne compte jamais en négatif quand l'horloge du téléphone est décalée (#1798)
- **Un seul dessin par rôle** : la période des stats, les métriques du journal, les onglets du minuteur, l'unité et le sexe des calculateurs, les catégories de badges, d'exercices et de mensurations passent par `GlassSegmented` et `GlassChip` ; les tuiles Homme/Femme et les ajouts rapides d'hydratation par `GlassTile` ; les trois champs multilignes par `GlassTextarea` ; les cartes de chiffres des séances et des mesures par `GlassStat` ; les 98 icônes hors composants de base par `GlassIcon`. La variation de poids n'est plus colorée par sa seule direction (#1801)
- **La charte nomme ses échelles** (#1784, #1785, #1803, #1808, #1809) : un jeton `text-2xs` et un utilitaire `sur-titre` remplacent 57 `text-[10px]` et leurs combinaisons ; sept plans nommés (`z-collant`, `z-nav`, `z-flottant`, `z-modale`, `z-toast`, `z-alerte`, `z-evitement`) remplacent dix valeurs dont `z-[9999]` ; six ombres et une ombre portée entrent dans les jetons ; les arrondis ont un rôle chacun ; six pas `stagger-1…6` remplacent soixante délais d'animation inline ; `transition` remplace `transition-all` (109 fois) ; le rembourrage vertical des pages appartient au layout ; les exemples de champ ne sont plus en capitales grasses. Une garde Vitest refuse le retour de toute valeur arbitraire

### Corrigé
- **Un record de volume s'annonçait en répétitions** : « 1 200 reps » pour une série de 100 kg × 12, et aucune unité du tout sur la carte de l'accueil. C'est une charge, en kilos
- **Un poids de trois chiffres se lisait à moitié dans sa case** (#1788) : les unités passent DANS les champs de la rangée d'une série, les flèches des champs numériques — que le doigt n'utilise jamais — libèrent leur douzaine de pixels, et une garde Dusk mesure le débordement réel du navigateur sur la séance, les plaques, le 1RM et l'échauffement

### Sécurité
- **Le manifeste de Vite ne répond plus** (#1816) : il listait tous les morceaux, leurs noms hachés et leurs imports — la carte de l'application, servie à qui la demandait. Laravel le lit sur disque

## [1.5.17] - 2026-09-06

### Corrigé
- **Le conteneur `app` démarrait sans ses caches** : `filament:upgrade`, lancé après `config:cache`, `route:cache` et `view:cache` dans l'entrypoint, enchaîne `config:clear`, `route:clear` et `view:clear`. Il n'y est plus ; les caches survivent au démarrage
- **L'image démarre sur une base neuve** (#1767) : le client MariaDB 11.8 de l'image vérifiait le certificat auto-signé de MySQL et refusait de charger le dump de schéma au premier `migrate`, donc le conteneur redémarrait en boucle. Un fichier d'options `[client]` désactive cette vérification pour `mysql` et `mysqldump`

### Ajouté
- **La santé surveille les tâches planifiées** (#1511) : une tâche échouée met le contrôle au rouge, une tâche en retard à l'orange, d'après le moniteur local
- **Les alertes de santé par courriel** : poser `HEALTH_TO_ADDRESS` suffit ; un contrôle au rouge écrit, une fois par heure au plus, et rien ne part sans adresse
- **Les pages « Tâches planifiées » et « Erreurs navigateur » ont une adresse courte** (`/backoffice/taches-planifiees`, `/backoffice/erreurs-navigateur`) au lieu du chemin déduit du nom de classe (`…/taches-planifiees/tache-planifiees`)
- **Le démarrage des conteneurs se contente de lire l'environnement** : paquets, lien de stockage, actifs du panneau, lecteur de journaux, vues et évènements sont figés dans l'image ; l'entrypoint ne lance plus que `config:cache`, `route:cache`, et pour `app` les migrations et le moniteur des tâches (deux à quatre commandes au lieu de six à dix). Sur un CPU bridé à 20 %, `/up` répond en 20 s au lieu de 36,5 s
- **La page « Sauvegardes » ne relit le partage qu'une fois par minute** au lieu de toutes les quatre secondes : c'était, page ouverte, la requête la plus fréquente de la semaine
- **MySQL de la pile synchronise son journal une fois par seconde** (`innodb_flush_log_at_trx_commit=2`) et n'écrit plus de journal binaire (`skip-log-bin`) : sur le disque de production, chaque écriture coûtait 250 à 500 ms de synchronisation. À appliquer à la pile ; une coupure brutale peut perdre jusqu'à une seconde d'écritures validées (#1668)
- **La CI démarre l'image sur une base vide avant de la publier** : même entrypoint et même commande que la pile, contre un MySQL 8.4 et un Redis jetables ; l'image qui ne répond pas sur `/up` n'est ni étiquetée ni promue (#1767 aurait été vue là)

### Retiré
- **Sentry côté serveur** (`sentry/sentry-laravel`, `SENTRY_DSN` et ses variables de la pile, les `sentryMonitor()` des tâches) : les exceptions se lisent dans le panneau (#1761), les tâches dans le moniteur local (#1762) et la santé écrit dès qu'une adresse est posée (#1773). Plus rien ne sort de la machine (#1511)

## [1.5.16] - 2026-09-06

### Ajouté
- **Les erreurs du navigateur se lisent dans le panneau** (#1511) : la page rapporte elle-même les erreurs non rattrapées, les promesses rejetées et les erreurs de rendu Vue (une fois par empreinte, dix au plus par page) à `erreurs-navigateur.store`, gardées trente jours et lues sous « Système › Erreurs navigateur »

### Modifié
- **Les actifs de `/build/assets` sont servis avec `Cache-Control: public, max-age=31536000, immutable`** : un fichier haché dans son nom ne change jamais sous son URL, le navigateur ne le redemande plus. FrankenPHP lit `docker/octane/Caddyfile`, copie du gabarit d'Octane gardée en phase par un test
- **Checkpoint ne signale plus les 42 paquets connus qui enregistrent des fonctions par `autoload.files`** : chacun a été lu, ils sont inscrits nom par nom dans `config/checkpoint.php` (pas de joker par éditeur), et le prochain paquet inconnu ressortira seul
- **Le seuil de couverture des branches JavaScript passe de 91 à 92 %** (mesuré 93,20 %) ; les trois autres seuils gardent leur point de marge (statements 96,04 %, functions 93,24 %, lines 96,63 %). Le README compte à nouveau les vrais tests : 1 748 Pest, 1 997 Vitest, 116 parcours Dusk

### Retiré
- **Le SDK Sentry du navigateur** (`@sentry/vue`, `SENTRY_DSN_PUBLIC`, le bloc `window.SENTRY_CONFIG`) : plus rien ne part du navigateur vers un tiers, et le morceau JavaScript principal s'allège d'autant. Sentry côté serveur reste en place

## [1.5.15] - 2026-09-06

### Ajouté
- **Les journaux se lisent depuis le panneau, et les outils y ont leur porte** : `opcodesio/log-viewer` sert `laravel.log` sous `/backoffice/journaux`, derrière la session du panneau, sa liste blanche d'adresses et la capacité du super administrateur ; le menu « Système » gagne Journaux, Horizon et, sur le poste de développement, Telescope, à côté de Pulse
- **Les tâches planifiées se lisent dans le panneau** (#1443) : `spatie/laravel-schedule-monitor` note chaque départ, fin ou échec des tâches quotidiennes, et « Système › Tâches planifiées » les montre avec leur fréquence en français, leur dernier passage et leur état (à l'heure, en retard au-delà de la marge, échouée) ; le planning se relit au déploiement et d'un bouton ; les trois tâches de santé, 1 728 passages par jour, restent hors moniteur pour épargner la base ; trente jours de journal, purgés
- **Les exceptions du serveur se lisent dans le panneau** (#1511) : `bezhansalleh/filament-exceptions` garde chaque exception rapportée (type, message, fichier, pile, requête, requêtes SQL) sous « Système › Exceptions », ouverte à qui porte la permission ; les cookies n'entrent pas en base et les champs et en-têtes sensibles y sont masqués ; trente jours d'historique, purgés par le planificateur. Sentry continue de recevoir les mêmes exceptions tant que #1511 n'est pas tranchée
- **Une page « Santé » dans le panneau** : `spatie/laravel-health` regarde la base, Redis, le cache, la file, le battement du planificateur, Horizon, l'espace disque, l'âge de la dernière sauvegarde, le mode débogage, l'environnement et les caches de configuration, toutes les cinq minutes en production ; les résultats vivent dans le cache (chaque écriture SQL coûte cher en production) et se lisent sous « Système › Santé », ouvert au super administrateur ; les notifications restent éteintes tant qu'aucune adresse n'est posée (`HEALTH_TO_ADDRESS`, `HEALTH_NOTIFICATIONS_ENABLED`)
- **Deux diagnostics de plus dans la CI** (#1491) : `laravel/doctor` vérifie l'autoload, le lock, la configuration et la cohérence du débogage (les contrôles d'infrastructure restent hors du job, qui n'a ni base ni cache), et `andreapollastri/checkpoint` passe vingt-deux contrôles de sécurité à la source et à la configuration, les audits de CVE restant à `composer audit` et OSV ; la fraîcheur des paquets s'annonce sans bloquer. Doctor a trouvé une classe d'aide de test hors PSR-4, déplacée dans `tests/Support`

### Modifié
- **Toute requête de validation ne vérifie que la connexion, et quatre requêtes que rien n'appelait partent** (#1676) : dix-huit requêtes rendaient encore `true` sans regarder l'utilisateur et une n'avait pas d'`authorize()`, ce que seul le middleware rattrapait ; les quatre variantes API du journal, du score Wilks et des compléments n'avaient plus d'appelant depuis que l'API ne sert que la page de séance ; une garde tient les deux règles, hors requêtes d'authentification qui servent des invités
- **Les écritures d'une série sont quatre composables qui se prêtent ce qu'ils partagent** : `useTransportDeSerie` (les deux appels au serveur, garés derrière la création en vol), `useSaisieDeSerie` (la rafale fondue en une écriture, son repli, le vidage, l'oubli des rafales), `useValidationDeSerie` (la coche et sa file) et `useAjoutEtRetraitDeSerie` (la naissance, sa chaîne, le retrait) ; `useSeriesDeLaSeance` ne fait plus que les composer pour la page, et passe de 779 à 109 lignes, chaque composable ayant sa suite

## [1.5.14] - 2026-09-06

### Corrigé
- **Un téléchargement de ChromeDriver réinitialisé ne fait plus tomber un éclat Dusk** (#1752) : le manifeste des versions vient d'un site tiers, et une connexion coupée y a mis `main` au rouge ; l'installation se retente trois fois avant de conclure
- **Le démarrage dit la vérité sur les migrations, et Horizon tient dans son conteneur** (#1630) : `migrate` n'est plus `--quiet`, donc la ligne où une série s'arrête se lit dans le journal ; Horizon passe de dix à trois processus en production, dans le plafond de 512 Mo du service `worker` au lieu d'en réclamer 1 280. Deux gardes tiennent ces deux faits

### Modifié
- **La bibliothèque d'exercices passe sous le plafond, et plus aucune page n'est en sursis** (#1675) : `FiltresDeLaBibliotheque` porte la recherche, les pastilles de catégorie et les raccourcis ⌘K et Échap, `NouvelExerciceModal` la création d'un exercice avec son formulaire, et `useFiltreDansLUrl` le filtre qui vit dans l'URL ; la page passe de 541 à 347 lignes, et la garde `tailleDesPages` ne tient plus qu'un plafond, sans exception
- **Le formulaire d'une habitude est un composant** (#1675) : `FormulaireDHabitude` porte la modale, le formulaire Inertia, les seize couleurs et les seize icônes avec leurs noms pour un lecteur d'écran, et crée ou met à jour selon l'habitude qu'on lui tend ; la page ne dit plus que sur quelle habitude elle s'ouvre, perd huit docblocks qui répétaient le code, et passe de 575 à 305 lignes, hors du sursis de la garde
- **Le minuteur d'intervalles vit dans un composable** (#1675) : `useMinuteurDIntervalles` porte la machine à phases (échauffement, travail, repos, terminé), le tic par seconde, les bips du décompte et des transitions, l'habillage de la phase et la libération de l'intervalle et du son quand la page part ; la page ne garde que ses onglets et ses préréglages, et passe de 548 à 334 lignes, hors du sursis de la garde
- **La page des séances passe sous le plafond, et l'indicateur de rafraîchissement devient un composant** (#1675) : `CarteDeSeance` porte la rangée de l'historique et son glissement de suppression, `GraphiquesDesSeances` les cinq cartes de graphiques, et `IndicateurDeRafraichissement` la pastille du tirer-pour-rafraîchir que la page des séances et la bibliothèque recopiaient à l'identique ; `Workouts/Index` passe de 483 à 322 lignes et sort du sursis de la garde, `Exercises/Index` de 572 à 541
- **L'abonnement aux notifications push vit dans un composable** (#1675) : `useAbonnementPush` porte ce que le serveur en connaît, ce que le navigateur en tient et l'activation étape par étape ; le formulaire des préférences ne garde que ses préférences, et son message « Enregistré. » s'éteint proprement quand on quitte la page ; la page passe de 417 à 246 lignes et sort du sursis de la garde
- **Les modèles de séance ajoutent leurs exercices par la même modale que la séance** (#1675) : `TemplateForm` abandonne sa propre modale (recherche, création sur le champ, bandeau d'erreur avec code HTTP) pour `AjoutDExerciceModal`, avec sa recherche mémorisée et ses erreurs en clair ; le contrôleur des exercices ne lit plus l'en-tête `X-Quick-Create`, la demande de JSON suffit

## [1.5.13] - 2026-09-05

### Corrigé
- **La sauvegarde de production échouait au dump** (#1740) : le client `mysqldump` de l'image est celui de MariaDB, qui vérifie le certificat du serveur MySQL, signé par lui-même, et refusait de se connecter ; le dump passe désormais sans TLS sur le réseau interne de la pile (`--loose-skip-ssl`, que le client MySQL du poste ignore) et sans lire les tablespaces, qui demandaient un privilège que l'utilisateur n'a pas

### Modifié
- **La page de séance passe sous quatre cents lignes, et une garde l'y tient** (#1675) : la fusion des props devient un util pur (`fusionnerLaSeance`), l'identité des rangées, le minuteur de repos et les réglages de la séance deviennent trois composables, le rapport de synchronisation pose lui-même ses écouteurs, et deux docblocks périmés s'effacent ; 2 527 lignes en début de chantier, 376 à l'arrivée. `tailleDesPages` refuse toute page au-dessus de quatre cents lignes et tient les cinq autres qui dépassent à leur compte du jour, qui ne peut que descendre
- **Les lignes de la séance vivent dans un composable, et leur retrait passe par le dialogue commun** (#1675) : `useLignesDeLaSeance` porte l'ajout d'un exercice, l'attente de la file hors ligne et le retrait ; la page abandonne sa propre modale de confirmation pour `ConfirmDialog`, celle des autres écrans, et passe de 807 à 641 lignes
- **La carte d'un exercice est un composant** (#1675) : `CarteDExercice` porte l'en-tête, la poignée de déplacement, les rangées de séries et le bouton qui en ajoute une, et relaie ce que chaque rangée dit en nommant la série ; la page passe de 894 à 807 lignes
- **La rangée d'une série est un composant** (#1675) : `RangeeDeSerie` porte la coche et son trophée, le numéro qui sert de poignée au clavier, les mesures selon le type d'exercice, le retrait par bouton ou par glissement et la pastille « non enregistrée » ; la page ne fait que relier ses écritures aux évènements de la rangée, et passe de 1 074 à 894 lignes
- **Les écritures des séries de la séance vivent dans un composable** (#1675) : `useSeriesDeLaSeance` porte l'ajout d'une série et sa chaîne de création, la saisie débouncée et son repli, la validation ordonnée derrière la saisie en attente, le retrait et sa remise en place ; la page ne garde que l'identité des rangées, le minuteur de repos et la fusion des props, et passe de 1 745 à 1 073 lignes
- **Les éclats Dusk et les tests PHP partent dès que les actifs sont construits** (#1674) : le job `frontend-build` enchaînait Prettier, ESLint, Vitest avec couverture puis seulement Vite, et tout ce qui dépendait des actifs attendait la minute et demie des tests JavaScript ; la construction et les tests sont deux jobs parallèles, `frontend-build` n'est plus que leur verdict, et la répartition des éclats Dusk est remesurée (93/67/85 s de tests par éclat, désormais 82/82/82)
- **Les tests de navigateur n'attendent plus de durées** (#1674) : cinquante `pause()` fixes, 39,8 s par passe, deviennent des conditions nommées — l'écriture arrivée en base, l'identifiant serveur reçu par une ligne optimiste, la mise en page stabilisée, ou un clic qui attend que sa cible cesse de bouger ; une garde refuse leur retour
- **Une seule hiérarchie de tests par contrôleur d'API** (#1674) : `tests/Feature/Api/V1` disparaît, ses cas rejoignent `tests/Feature/Api`, et quatre fichiers qui répétaient la création d'une série ou d'une ligne sont retirés ; le test du contrôleur des lignes couvre en plus l'ordre 0 de la première ligne, seul cas que les doublons tenaient encore

## [1.5.12] - 2026-09-05

### Corrigé
- **Le bouton « Créer une sauvegarde » de Filament était caché à tout le monde** : le greffon demande une capacité `create-backup` que Shield ne connaît pas et ne pose aucune porte pour le super administrateur ; les trois capacités (créer, télécharger, supprimer) sont désormais ouvertes au super administrateur, et la garde « pas d'archive sans mot de passe » couvre ce chemin
- **Les graphiques suivent trois règles communes, page après page** : les dates d'un axe s'écrivent `jj/mm` partout (quatre formats se côtoyaient), les cartes d'une page montrent leurs deux axes et seuls les encarts du tableau de bord et les vignettes de la page de statistiques restent nus, et un graphique remplit la hauteur de sa carte au lieu d'y laisser un vide
- **Les anneaux du tableau de bord n'ont plus deux allures** : l'un avait des bordures entre ses parts et l'autre non, et celui dont la légende passait sur deux lignes était plus fin. L'habillage des parts vient désormais du graphique de base, et la légende d'un anneau se dessine sous le canevas plutôt que dedans

### Modifié
- **L'ajout d'exercice de la séance est un composant** (#1675) : `AjoutDExerciceModal` porte la recherche (dont la mémoire d'une séance à l'autre), le choix dans la bibliothèque et la création sur le champ avec ses erreurs ; la page ne fait plus qu'ajouter la ligne
- **L'ordre de la séance vit dans un composable** (#1675) : `useOrdreDeLaSeance` porte le réordonnancement des exercices et des séries, au doigt comme aux flèches, l'écriture de l'ordre entier au serveur et son repli à l'ordre confirmé ; la page de séance passe sous deux mille lignes (2 527 → 1 745 depuis le début de son éclatement)
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
- **Le service worker et le manifeste sont des fichiers statiques** à la racine de `public/` (le manifeste est versionné, le worker construit) : plus de route Laravel pour les servir, donc plus de session ouverte ni de cookie posé à chaque vérification du worker (une écriture de moins en production par ouverture de l'application).
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
- **La liste blanche du panneau accepte les plages CIDR** (#1664) : `ADMIN_ALLOWED_IPS` prend des adresses exactes ou des plages, IPv4 et IPv6, pour couvrir un réseau privé sans lister chaque appareil.

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
- **Une sonde de santé propre au planificateur** (#1679) : le service `scheduler` héritait du `HEALTHCHECK` de l'image, qui interroge un serveur web absent de ce conteneur ; il était « unhealthy » à vie. Il vérifie désormais que l'application démarre et liste ses tâches.

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

[Unreleased]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.5.19...HEAD
[1.5.19]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.5.18...v1.5.19
[1.5.18]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.5.17...v1.5.18
[1.5.17]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.5.16...v1.5.17
[1.5.16]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.5.15...v1.5.16
[1.5.15]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.5.14...v1.5.15
[1.5.14]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.5.13...v1.5.14
[1.5.13]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.5.12...v1.5.13
[1.5.12]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.5.11...v1.5.12
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

---
paths:
  - 'tests/**'
---

# Tests

## Les fonctions d'aide Pest sont globales : préfixer par le sujet
Une `function` déclarée dans un fichier de test Pest est GLOBALE à toute la suite. Deux fichiers qui déclarent `seanceLe()` ou `recordDePoids()` avec des signatures différentes se percutent, et l'erreur remonte en `arguments.count` PHPStan sur le fichier innocent — pas là où est le doublon.

Nommer l'aide d'après ce qu'elle fait ET son sujet : `seanceIlYA()` plutôt que `seanceLe()`, `recordMaxDe()` plutôt que `recordDePoids()`. Avant d'en ajouter une, `grep -rn "function nomChoisi" tests`.

Vu deux fois dans la même session (#1614, #1615).

## Une date posée en dur expire si le code la compare à maintenant
Un littéral comme `'measured_at' => '2026-06-03'` est dans la fenêtre le jour où on l'écrit, et en sort tout seul. Le test échoue alors des mois plus tard, sur une PR qui ne touche à rien de proche — donc on cherche la cause au mauvais endroit.

Vu le 01/09/2026 : `StatsCachePoliciesTest` posait une mesure au 03/06, `BodyStatsService` borne à `now()->subDays(90)`. Quatre-vingt-dix jours pile : la CI est passée au rouge pendant la nuit, sur une PR de cibles tactiles.

Ce n'est pas la date seule qui est en cause, mais le couple **date absolue × fenêtre relative**. Un autre témoin porte la même date du 03/06 et passe, parce que rien ne la compare à `now()`.

Donc : dès qu'un test pose une date que le code comparera à `now()`, arrêter l'horloge (`Carbon::setTestNow(...)`). Cela garde les valeurs littérales, ce qui est leur intérêt — préférable à `now()->subDays(30)` des deux côtés, qui fait un test qui se compare à lui-même.

## Un admin de test doit recevoir un rôle ou une permission Shield pour entrer dans le panneau
`Admin::canAccessPanel()` exige au moins un rôle ou une permission Shield (#1664, 2026-09-03) : une ligne nue dans `admins` répond 403 sur tout `/backoffice`. Dans un test, passer par `Tests\Support\FilamentAdminPanel::admin([...permissions])`, ou assigner un rôle (`Role::findOrCreate('invite', 'admin')`) quand on veut un admin qui entre mais ne voit aucune ressource. Le seeder `AdminSeeder` exige `ADMIN_INITIAL_PASSWORD` non vide et ne réécrit jamais un mot de passe existant ; `IpWhitelist` ferme le panneau en production quand `ADMIN_ALLOWED_IPS` est vide, mais le laisse ouvert hors production.

## Un test de navigateur n'attend jamais une durée
`->pause(n)` est un pari sur la vitesse de la machine : cinquante pauses, 39,8 s par passe, retirées le 05/09/2026 (#1674), et `PausesDuskTest` refuse leur retour. Nommer la condition : `waitFor*` / `waitUntil*` pour un état du DOM ; `clickWhenSettled('[dusk="…"]')` avant de cliquer un élément qui vient d'apparaître ou de défiler (il centre, attend l'immobilité et vérifie que le clic a atteint sa cible) ; `waitForServerIds()` quand une ligne optimiste doit tenir sa réponse serveur ; `waitForStableLayout()` avant une mesure de mise en page ; `$this->waitForDatabase(fn (): bool => …)` pour une écriture débouncée. Un pointeur JavaScript (`script('….click()')`) n'a besoin ni de défilement ni d'attente.

## Une application démarrée dans un test relit le `.env` par-dessus les variables posées
Le dépôt de variables de Laravel (`Env::getRepository()`) est statique et immuable, mais il n'épargne que les variables définies hors de lui : celles que le `.env` lui a fait charger au démarrage du test, il les réécrit au démarrage suivant. Un worker Octane démarré dans un test (`tests/Support/WorkerOctane.php`) perdait ainsi la valeur posée de toute variable du `.env` absente de `phpunit.xml` : avec le `.env` copié de `.env.example`, `ADMIN_ALLOWED_IPS=` vidait la liste, et le worker répondait 404 en production. Le défaut ne se voit qu'en lancement séquentiel (`artisan test --compact <fichier>`, `vendor/bin/pest`) : en parallèle (`artisan test -p`, la CI ; `pest --parallel`, la mutation), les variables arrivent du processus parent et comptent comme extérieures. `WorkerOctane` remet le dépôt à neuf (`Env::enablePutenv()`) avant le démarrage et exige ensuite que chaque variable posée atteigne le worker ; `WorkerOctaneTest` le rejoue sans dépendre du `.env` de la machine. Tout autre démarrage d'une application neuve dans un test suit la même voie, et se vérifie avec le `.env` de `.env.example`, en séquentiel.

## PHPUnit 13 ne lit que les attributs, et `in()` n'atteint que les fichiers Pest
PHPUnit a cessé de lire les docblocks à sa version 12 : une étiquette qui réglait un test (test, dataProvider, beforeClass…) ne fait plus rien, la méthode n'est ni lancée, ni alimentée, ni appelée, et rien ne le signale. Le `prepare()` de DuskTestCase est resté mort ainsi de mars 2026 à #1927, où il a été retiré, pas réveillé : la CI et Sail démarrent déjà le pilote (`.ai/rules/browser.md`). Ailleurs, écrire l'attribut (`#[BeforeClass]`, `#[DataProvider('…')]`), ou `beforeAll()` / `beforeEach()` dans un fichier Pest. De même, `pest()->…->in('Dossier')` dans `tests/Pest.php` ne s'applique qu'aux fichiers qui appellent `it()` ou `test()` : une classe PHPUnit du dossier garde sa classe parente et ses traits. `LesCrochetsDesTestsSontLusTest` (tests/Feature/Conventions) refuse une étiquette qui porte le nom d'un attribut de PHPUnit, et une liaison vers un dossier sans test Pest.

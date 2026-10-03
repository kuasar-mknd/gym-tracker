---
paths:
  - 'tests/Browser/**'
---

# Browser

## Le navigateur vit en UTC, l'application à Paris : lire « aujourd'hui » dans le navigateur
Chrome tourne dans le fuseau de la machine qui le lance (UTC sur les exécuteurs GitHub et dans le conteneur `selenium`), la suite dans `Europe/Paris`. Un test qui pose des données à `now()` et attend une case, un marqueur ou un « aujourd'hui » que le client calcule avec `new Date()` tombe chaque nuit entre 00 h et 02 h Paris (#1752, #1754). Lire la date du navigateur (`$browser->script('… new Date() …')`) et poser les données à midi de cette date dans `config()->string('app.timezone')`. Reproduire en local : lancer Dusk dans la fenêtre, le conteneur `selenium` est en UTC.

## La suite ne démarre pas le pilote, et `tests/Pest.php` ne lie rien aux parcours
Les parcours se branchent sur le pilote que désigne `DUSK_DRIVER_URL` : la CI lance ChromeDriver dans l'étape « Start Chrome Driver » du job browser-shard, Sail fournit le conteneur `selenium`. Aucun crochet de `tests/DuskTestCase.php` ne le démarre. L'ancien `prepare()`, qui le faisait hors Sail et posait APP_ENV=testing, ne tenait qu'à une étiquette de docblock que PHPUnit 13 ignore : il n'a jamais tourné, et l'éveiller doublerait le ChromeDriver de la CI et poserait APP_ENV=testing dans le processus des parcours, quand Sail garde `local` à dessein (`config/app.php`, « Browser Test Run ») : sous `artisan dusk`, `getenv()` et la configuration diraient alors deux environnements différents (#1927). Les parcours sont des classes PHPUnit, que `in()` n'atteint pas : une classe qui doit partir d'une base vide porte elle-même `use DatabaseTruncation;` (quatorze le font). L'ajouter aux autres changerait leur comportement et la durée des passes.

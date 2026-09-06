---
paths:
  - 'tests/Browser/**'
---

# Browser

## Le navigateur vit en UTC, l'application à Paris : lire « aujourd'hui » dans le navigateur
Chrome tourne dans le fuseau de la machine qui le lance (UTC sur les exécuteurs GitHub et dans le conteneur `selenium`), la suite dans `Europe/Paris`. Un test qui pose des données à `now()` et attend une case, un marqueur ou un « aujourd'hui » que le client calcule avec `new Date()` tombe chaque nuit entre 00 h et 02 h Paris (#1752, #1754). Lire la date du navigateur (`$browser->script('… new Date() …')`) et poser les données à midi de cette date dans `config()->string('app.timezone')`. Reproduire en local : lancer Dusk dans la fenêtre, le conteneur `selenium` est en UTC.

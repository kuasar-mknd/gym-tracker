---
paths:
  - 'database/**'
  - 'app/**'
  - 'config/**'
  - 'routes/**'
---

# Base de données

## Le SQL de l'application n'appelle ni `md5()`, ni `sha1()`, ni `sha()` : `SHA2()` les remplace
Réglé le 2026-10-17 (#2086). MySQL 9.7 sort `md5()` et `sha1()` (dont `sha()` est l'alias) des fonctions natives : elles passent dans un composant à installer, comme fonctions chargeables. Sans lui, une requête qui les appelle échoue ; avec lui, une colonne générée les refuse encore, et un serveur 9.7 ne démarre pas sur une base 8.4 qui les appelle dans une colonne générée virtuelle indexée (celle de Pulse), un défaut calculé, un index fonctionnel ou une contrainte CHECK ; une colonne générée stockée, une vue, un déclencheur, une routine ou un événement passent la mise à niveau, puis tombent à leur premier usage. Avant la bascule, `docker/mysql/avant-mysql-9-7.sql` cherche chacun de ces objets dans chaque base du serveur (`.ai/rules/dependencies.md`, « MySQL 9.7 : la CI d'abord… », étape 3). Une migration, le schéma figé (`database/schema/mysql-schema.sql`), une requête brute d'un modèle ou d'un service (`DB::raw()`, `selectRaw()`, `whereRaw()`…) et la configuration prennent donc `SHA2(x, 256)` : ``unhex(left(sha2(`key`,256),32))`` pour une empreinte de seize octets, comme celle des clés de Pulse. `md5()` et `hash()` de PHP restent permis : la contrainte porte sur le SQL envoyé au serveur. `AucunSqlNAppelleMd5NiSha1Test` (tests/Feature/Conventions) lit le schéma figé et chaque chaîne des fichiers PHP de `app`, `config`, `database` et `routes`, et refuse un appel de ces trois fonctions.

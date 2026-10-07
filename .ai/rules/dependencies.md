---
paths:
  - 'package.json, package-lock.json, composer.json, composer.lock'
---

# Dependencies

## Mettre les dépendances à jour ne demande pas d'accord, majeures comprises
Décision du propriétaire du dépôt (2026-10-02, #1902 et #1903) : la règle « pas de changement de dépendance sans accord » de `CLAUDE.md` ne s'applique pas ici. Monter une dépendance — correctif de faille, version mineure, version majeure — se fait sans demander, et c'est une obligation, pas une permission : les majeures aussi se montent, avec le code, la configuration et les tests qu'elles exigent, plutôt que d'être reportées. Une dépendance en retard qu'on remarque en passant se monte. Chaque montée se vérifie comme n'importe quel changement : suite complète, PHPStan, Rector, Pint, PHP Insights, OSV, `npm ci`, construction, `lint:js`, Vitest. Seul ce qui ne peut réellement pas monter — un paquet dont une dépendance n'a pas encore publié de version compatible — reste en arrière, avec le blocage nommé dans la PR et repris dès qu'il se lève.

## Une faille signalée se corrige tout de suite, même hors du sujet de la PR
Quand `composer audit`, l'étape OSV du job `audit` ou tout autre signalement vise une dépendance, on monte la version corrigée dans la PR en cours, quelle que soit la gravité : le seuil CVSS 7 de la CI dit seulement ce qui bloque, pas ce qui se corrige. Pour une dépendance transitive npm, monter le plancher de son entrée dans `overrides` de `package.json` (une entrée par majeure fautive, voir plus bas), ou `npm update <paquet>` si sa plage l'admet déjà. Une entrée « Sécurité » dans le journal accompagne la montée.

## Une entrée d'`overrides` vise la majeure fautive, jamais le paquet entier
Une clé sans sélecteur s'applique à toutes les copies du paquet, quelle que soit la plage de leur consommateur. « brace-expansion », posée pour une faille de sa 5 (#1902), imposait ainsi la 5 au minimatch 5 de `filelist`, qui attend ^2.0.1 : brace-expansion 5 n'exporte plus de fonction par défaut, ce minimatch levait « expand is not a function » sur toute accolade, et rien ne rougissait, parce que npm installe ce qu'un override lui dit sans vérifier la plage du consommateur. Une entrée vise donc la majeure fautive, `paquet@N` avec une version de cette majeure (`"brace-expansion@5": "^5.0.12"`, `"nanoid@3": "^3.3.18"`) ; une autre majeure fautive du même paquet prend sa propre entrée. `LesOverridesNpmRespectentLeursConsommateursTest` (tests/Feature/Conventions) lit package.json et package-lock.json comme npm et Node : il refuse une clé sans `@N`, une version imposée hors de sa majeure, un consommateur qui charge une autre majeure que celle qu'il déclare ou une version plus ancienne que sa plage, et un override dont plus aucun paquet installé ne dépend. Une version plus récente de la majeure déclarée passe, même hors de la plage : c'est la forme d'un plancher relevé pour une faille chez un consommateur qui fige sa dépendance (concurrently, à sa dernière version, déclare `shell-quote` « 1.9.0 », et `"shell-quote@1": "^1.12.0"` lui sert la version corrigée).

## Les outils qui régénèrent les verrous
Le lock npm se régénère avec npm 11, celui de Node 24 comme la CI (`npx -y npm@11 install --package-lock-only`) : npm 10 réécrit le lock, retire les champs `libc` et déplace des entrées. Composer tourne sous PHP 8.5, comme l'image et la CI : sous une autre version, `--ignore-platform-reqs` laisse résoudre des versions que la production refuserait. Vérifier les paquets npm avec l'image de la CI, `ghcr.io/google/osv-scanner:v2.6.0 scan --lockfile=package-lock.json`.

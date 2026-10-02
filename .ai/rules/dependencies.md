---
paths:
  - 'package.json, package-lock.json, composer.json, composer.lock'
---

# Dependencies

## Mettre les dépendances à jour ne demande pas d'accord
Décision du propriétaire du dépôt (2026-10-02, #1902 puis la PR de mise à jour qui l'a suivie) : la règle « pas de changement de dépendance sans accord » de `CLAUDE.md` ne s'applique pas ici. Monter une dépendance — correctif de faille, version mineure, version majeure — se fait sans demander, à condition de la vérifier comme n'importe quel changement : suite complète, PHPStan, Rector, Pint, OSV, `npm ci`, construction, `lint:js`, Vitest. Une dépendance en retard qu'on remarque en passant se signale et se monte, plutôt que de rester en l'état « faute d'accord ». Une majeure qui exige un vrai chantier peut partir dans sa propre PR, avec la raison écrite dans la PR principale ; elle ne s'abandonne pas en silence.

## Une faille signalée se corrige tout de suite, même hors du sujet de la PR
Quand `composer audit`, l'étape OSV du job `audit` ou tout autre signalement vise une dépendance, on monte la version corrigée dans la PR en cours, quelle que soit la gravité : le seuil CVSS 7 de la CI dit seulement ce qui bloque, pas ce qui se corrige. Pour une dépendance transitive npm, monter le plancher de son entrée dans `overrides` de `package.json`, ou `npm update <paquet>` si sa plage l'admet déjà. Une entrée « Sécurité » dans le journal accompagne la montée.

## Les outils qui régénèrent les verrous
Le lock npm se régénère avec npm 11, celui de Node 24 comme la CI (`npx -y npm@11 install --package-lock-only`) : npm 10 réécrit le lock, retire les champs `libc` et déplace des entrées. Composer tourne sous PHP 8.5, comme l'image et la CI : sous une autre version, `--ignore-platform-reqs` laisse résoudre des versions que la production refuserait. Vérifier les paquets npm avec l'image de la CI, `ghcr.io/google/osv-scanner:v2.5.1 scan --lockfile=package-lock.json`.

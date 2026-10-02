---
paths:
  - 'package.json, package-lock.json, composer.json, composer.lock'
---

# Dependencies

## Une faille signalée se corrige tout de suite, même hors du sujet de la PR
Décision du propriétaire du dépôt (2026-10-02, #1902) : quand `composer audit`, l'étape OSV du job `audit` ou tout autre signalement vise une dépendance, on monte la version corrigée dans la PR en cours, sans demander, et quelle que soit la gravité — le seuil CVSS 7 de la CI dit seulement ce qui bloque, pas ce qui se corrige. C'est la seule exception à « pas de changement de dépendance sans accord » de `CLAUDE.md`. Pour une dépendance transitive npm, monter le plancher de son entrée dans `overrides` de `package.json`, ou `npm update <paquet>` si sa plage l'admet déjà. Régénérer le lock avec npm 11, celui de Node 24 comme la CI (`npx -y npm@11 install --package-lock-only`) : npm 10 réécrit le lock, retire les champs `libc` et déplace des entrées. Vérifier avec l'image de la CI, `ghcr.io/google/osv-scanner:v2.5.1 scan --lockfile=package-lock.json`, puis `npm ci`, la construction, `lint:js` et Vitest. Une entrée « Sécurité » dans le journal accompagne la montée.

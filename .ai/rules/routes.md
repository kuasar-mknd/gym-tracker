---
paths:
  - routes/web.php
---

# Routes

## Aucun fichier demandé par le service worker ne passe par une route du groupe web
Le worker (`public/sw.js`, construit) et le manifeste (`public/manifest.webmanifest`, versionné) sont des fichiers statiques servis sans PHP. Ne jamais les remettre derrière une route du groupe `web` : `StartSession` et `PreventRequestForgery` posent un `Set-Cookie` de session sur chaque réponse, et une requête du precache partie sans cookie écrase celui de l'utilisateur ou du test Dusk suivant (cinq runs Dusk sur six rouges après #1698, corrigé par #1704 le 2026-09-04). Même règle pour tout fichier récupéré hors session (icônes, polices) : `public/` ou `public/build/`, jamais une route.

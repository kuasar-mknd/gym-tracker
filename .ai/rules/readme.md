---
paths:
  - 'README.md, .env.example, docker-compose.prod.yml, compose.yaml, Dockerfile, entrypoint.sh, docker/**, config/**'
---

# Readme

## Le README se tient à jour dans la PR qui le rend faux, à commencer par les variables d'environnement
Décision du propriétaire du dépôt (2026-10-02) : le README est maintenu, pas réécrit de temps en temps. Toute PR qui ajoute, renomme, retire une variable d'environnement ou en change le rôle ou la valeur par défaut — `env()` dans `config/`, `${…}` dans `docker-compose.prod.yml`, `ENV` du `Dockerfile`, `entrypoint.sh`, le Caddyfile, une `VITE_*` du front, un secret de CI — met à jour dans la même PR la section « Variables d'environnement » du README et `.env.example`. Même chose pour tout autre fait du README que la PR rend faux : nombre de tests, seuils, commandes, services, versions. `LeReadmeDocumenteLesVariablesTest` (tests/Feature/Conventions) refuse une variable de `docker-compose.prod.yml` ou de `.env.example` que le README ne nomme pas entre accents graves ; `DocumentationSansDeriveTest` tient déjà les chemins et les versions majeures.

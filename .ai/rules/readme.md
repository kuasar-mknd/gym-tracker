---
paths:
  - 'README.md, .env.example, docker-compose.prod.yml, compose.yaml, Dockerfile, entrypoint.sh, docker/**, config/**'
---

# Readme

## Le README se tient à jour dans la PR qui le rend faux, à commencer par les variables d'environnement
Décision du propriétaire du dépôt (2026-10-02) : le README est maintenu, pas réécrit de temps en temps. Toute PR qui ajoute, renomme, retire une variable d'environnement ou en change le rôle ou la valeur par défaut — `env()` dans `config/`, `${…}` dans `docker-compose.prod.yml`, `ENV` du `Dockerfile`, `entrypoint.sh`, le Caddyfile, une `VITE_*` du front, un secret de CI — met à jour dans la même PR la section « Variables d'environnement » du README et `.env.example`. Même chose pour tout autre fait du README que la PR rend faux : nombre de tests, seuils, commandes, services, versions. `LeReadmeDocumenteLesVariablesTest` (tests/Feature/Conventions) refuse une variable de `docker-compose.prod.yml`, de `.env.example` ou un secret de CI que le README ne nomme pas entre accents graves, et une variable du tableau « Production » que la composition ne transmet pas — le défaut d'origine : `HORIZON_ALLOWED_EMAILS` et `ADMIN_INITIAL_PASSWORD` étaient documentées pour la pile et n'atteignaient aucun conteneur. Une variable lue par `config/` sans être transmise par la composition n'a aucun effet en production ; la transmettre, c'est l'ajouter à `docker-compose.prod.yml` avec `${NOM:-défaut}` (une variable oubliée sans défaut arrive vide, et Laravel prend la chaîne vide, pas le défaut de sa configuration). `DocumentationSansDeriveTest` tient déjà les chemins et les versions majeures.

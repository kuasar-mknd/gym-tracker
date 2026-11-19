# Documentation

Le `README.md` de la racine suffit pour installer, lancer, tester et déployer. Ce dossier dit le détail.

Déployer et exploiter :

- `docs/variables-d-environnement.md` : toutes les variables (production, fixées par la composition ou l'image, lues non transmises, front, local, secrets de CI).
- `docs/deploiement.md` : le détail du déploiement (mise à jour, Redis, secrets, pile, proxy inverse).
- `docs/mise-a-niveau.md` : ce que la prochaine version fait une fois au déploiement.
- `docs/exploitation.md` : santé, mesure de la base, journaux, ce qui reste d'un compte supprimé.
- `docs/diagnostic.md` : l'aperçu et l'accès de diagnostic.
- `docs/releve-des-temps.md` : la procédure du relevé des temps.
- `docs/publication-des-versions.md` : ce qu'exige la publication d'un tag.

Comprendre :

- `docs/securite.md` : garanties de sécurité et double authentification du panneau.
- `docs/hors-ligne.md` : ce que l'appareil garde hors ligne, copies des pages, ouverture sans réseau.
- `docs/adr/` : décisions d'architecture.
- `docs/charte.html` : charte graphique (générée par `php artisan charte:publier`).

Développer :

- `docs/developpement.md` : qualité et seuils, pile technique, charte, commandes, parcours navigateur, mutation.
- `CONTRIBUTING.md` : le guide du contributeur.

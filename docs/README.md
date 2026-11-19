# Documentation

Le [README](../README.md) de la racine suffit pour installer, lancer, tester et déployer. Ce dossier dit le détail.

Déployer et exploiter :

- [variables-d-environnement.md](variables-d-environnement.md) : toutes les variables (production, fixées par la composition ou l'image, lues non transmises, front, local, secrets de CI).
- [deploiement.md](deploiement.md) : le détail du déploiement (mise à jour, Redis, secrets, pile, proxy inverse).
- [mise-a-niveau.md](mise-a-niveau.md) : ce que la prochaine version fait une fois au déploiement.
- [exploitation.md](exploitation.md) : santé, mesure de la base, journaux, ce qui reste d'un compte supprimé.
- [diagnostic.md](diagnostic.md) : l'aperçu et l'accès de diagnostic.
- [releve-des-temps.md](releve-des-temps.md) : la procédure du relevé des temps.
- [publication-des-versions.md](publication-des-versions.md) : ce qu'exige la publication d'un tag.

Comprendre :

- [securite.md](securite.md) : garanties de sécurité et double authentification du panneau.
- [hors-ligne.md](hors-ligne.md) : ce que l'appareil garde hors ligne, copies des pages, ouverture sans réseau.
- [adr/](adr/) : décisions d'architecture.
- [charte.html](charte.html) : charte graphique (générée par `php artisan charte:publier`).

Développer :

- [developpement.md](developpement.md) : qualité et seuils, pile technique, charte, commandes, parcours navigateur, mutation.
- [CONTRIBUTING.md](../CONTRIBUTING.md) : le guide du contributeur.

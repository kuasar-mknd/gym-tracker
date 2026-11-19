# Documentation

Le [README](../README.md) de la racine suffit pour installer, lancer, tester et déployer. Ce dossier donne le détail.

Chaque page répond à une seule question. Choisissez la vôtre.

## Déployer et exploiter la production

| Page | Elle répond à… | À lire quand… |
| --- | --- | --- |
| [deploiement.md](deploiement.md) | Comment déployer, mettre à jour et défaire une mise à jour ? | vous installez la production ou passez à la release suivante |
| [mise-a-niveau.md](mise-a-niveau.md) | Que change la prochaine version au déploiement ? | juste avant chaque mise à jour |
| [variables-d-environnement.md](variables-d-environnement.md) | Quelle variable poser, avec quelle valeur ? | vous configurez la pile, le développement ou la CI |
| [exploitation.md](exploitation.md) | Comment surveiller et entretenir la production ? | un contrôle de santé est rouge, ou vous cherchez les journaux |
| [diagnostic.md](diagnostic.md) | Comment l'agent de maintenance lit-il l'état de la production ? | vous ouvrez, limitez ou retirez un accès de diagnostic |
| [releve-des-temps.md](releve-des-temps.md) | Comment chiffrer le temps de réponse de chaque écran ? | vous mesurez un changement de performance |
| [publication-des-versions.md](publication-des-versions.md) | Comment publier une version, et que faire si elle ne part pas ? | vous posez un tag `v*` |

## Comprendre comment c'est fait

| Page | Elle répond à… | À lire quand… |
| --- | --- | --- |
| [pile-de-production.md](pile-de-production.md) | Comment la pile est construite, et pourquoi ? | vous changez `docker-compose.prod.yml` ou voulez savoir pourquoi un réglage existe |
| [securite.md](securite.md) | Que garantit l'application, et comment marche la double authentification du panneau ? | vous touchez à la connexion ou au panneau |
| [hors-ligne.md](hors-ligne.md) | Que garde l'appareil hors ligne, et comment l'application s'ouvre sans réseau ? | vous touchez aux copies, à la file d'écritures ou au service worker |
| [adr/](adr/) | Quelles décisions d'architecture ont été prises ? | vous remettez une décision en cause |
| [charte.html](charte.html) | Quelles couleurs et quels contrastes ? | vous touchez au style (page générée par `php artisan charte:publier`) |

## Développer

| Page | Elle répond à… | À lire quand… |
| --- | --- | --- |
| [developpement.md](developpement.md) | Comment développer et vérifier le code ? | vous lancez les tests, les outils qualité ou les parcours navigateur |
| [tests-de-mutation.md](tests-de-mutation.md) | Comment mesurer le score de mutation ? | une classe doit tenir son seuil de mutation |
| [CONTRIBUTING.md](../CONTRIBUTING.md) | Comment proposer une contribution ? | vous ouvrez une PR |

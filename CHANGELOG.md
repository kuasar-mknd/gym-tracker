# Journal des modifications

Toutes les modifications notables de GymTracker seront documentées dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/),
et ce projet adhère au [Versionnage Sémantique](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.5.0] - 2026-03-17

### Ajouté

- **Architecture 2026** : Introduction d'Enums PHP 8.5 pour les types de records (`PersonalRecordType`), les objectifs (`GoalType`) et les catégories d'exercices (`ExerciseCategory`).
- **Services Spécialisés** : Décomposition du `StatsService` monolithique en services granulaires : `VolumeStatsService`, `BodyStatsService`, `WorkoutStatsService`, `ExerciseStatsService` et `StatsCacheManager`.
- **Extraction Logique Métier** : Création de `RecommendedValuesService` pour isoler la logique de calcul des suggestions, allégeant le modèle `WorkoutLine`.

### Modifié

- **Refactorisation du Dashboard** : Décomposition de `Dashboard.vue` en 8 sous-composants spécialisés pour une maintenabilité accrue.
- **Réorganisation des Composants** : Restructuration complète de `resources/js/Components/` avec des dossiers `UI/`, `Form/` et `Navigation/`.
- **Organisation des Tests** : Reorganisation des tests Feature dans des sous-dossiers thématiques (`Controllers/`, `Models/`, `Services/`).
- **Squash des Migrations** : Consolidation de 73 migrations en un seul fichier de schéma (`schema:dump`) pour une initialisation de base de données ultra-rapide.

### Optimisé

- **Nettoyage de la Racine** : Suppression des fichiers de logs CI parasites et mise à jour du `.gitignore`.
- **Standardisation i18n** : Traduction des dernières chaînes hardcodées dans le backend vers les fichiers de langue JSON.
- **Modernisation PHP** : Mise à jour de la documentation et des configurations vers PHP 8.5.

## [1.4.18] - 2026-03-06

### Ajouté

- **Recommandations Intelligentes** : Implémentation de suggestions de valeurs intelligentes pour les séries (poids/répétitions) basées sur les données les plus fréquentes de la séance la plus récente du même exercice.
- **Stabilité E2E** : Atteinte de 100 % de fiabilité pour les tests de navigation sur toutes les tailles d'iPhone (Mini, 15, Max).
- **E2E Bibliothèque d'exercices** : Ajout de tests de cycle de vie complets pour la bibliothèque d'exercices (Recherche, Filtrage, Création, Modification, Suppression).
- **Trophées PR** : Intégration de retours visuels (étoile dorée) directement sur les séries atteignant un nouveau record personnel (PR).

### Modifié

- **UX Mobile** : Affinement de la sensibilité de `SwipeableRow` avec verrouillage de direction pour éviter les glissements accidentels lors du défilement vertical.
- **Mise en page mobile** : Amélioration des marges (padding) et des zones de sécurité (safe-area insets) pour garantir que les boutons d'action critiques (Terminer l'entraînement) ne soient jamais masqués par les barres de navigation.
- **Retours Inertia** : Intégration des messages flash (succès/erreur) directement dans la mise en page authentifiée via les propriétés partagées Inertia.

### Corrigé

- **Infrastructure CI** : Réparation du pipeline GitHub Actions en corrigeant les problèmes de manifeste Vite et les permissions de connexion MySQL.
- **Logique d'entraînement** : Correction des problèmes de rendu des cartes lors de l'ajout de nouveaux exercices pendant une séance active.
- **Qualité du code** : Obtention d'un score de 100/100 dans toutes les catégories PHP Insights sur la branche principale stable.

## [1.4.14] - 2026-03-02

### Ajouté

- **Dénormalisation du volume** : Ajout de `workout_volume` aux entraînements (`workouts`) et `total_volume` aux utilisateurs (`users`) pour un calcul des statistiques quasi instantané.
- **Synchronisation en temps réel** : Implémentation de la synchronisation automatisée du volume via les événements Eloquent, garantissant la cohérence des données sans surcharge lors de la lecture.

### Optimisé

- **Optimisation des stats** : Refactorisation de `StatsService` pour exploiter les données dénormalisées, réduisant le temps de requête du tableau de bord de plus de 80 %.
- **Gestion de la mémoire** : Optimisation de la commande `TrainingReminderCommand` avec un traitement par lots (chunking) et un chargement avide (eager loading) pour gérer les bases d'utilisateurs importantes.
- **Réduction de la charge utile** : Ajout de limites de sécurité aux points de terminaison de données historiques (Poids, Journal, Chronomètres) pour éviter des charges utiles JSON massives.

### Corrigé

- **Fiabilité CI** : Stabilisation définitive de GitHub Actions en basculant tous les tests sur MySQL, résolvant les échecs intermittents de migration SQLite.
- **CI : Isolation de l'environnement** : Correction de la préservation de `APP_KEY` et désactivation stricte de Telescope/Pulse dans les environnements de test pour éviter les erreurs 500.
- **CI : Harmonisation des tests** : Résolution des collisions de traits entre `RefreshDatabase` et `DatabaseMigrations` dans la suite de tests.
- **Authentification E2E** : Correction des erreurs 401 dans Dusk en activant l'API d'état Sanctum et en configurant Axios avec les identifiants.
- **Invalidation du cache** : Correction d'un bug dans le modèle `Exercise` où les clés de cache versionnées n'étaient pas correctement invalidées.
- **Robustesse Dusk** : Amélioration des sélecteurs et ajout des pauses nécessaires dans `ExerciseManagementTest` pour gérer les animations.

## [1.4.13] - 2026-02-28

### Sécurité

- **FormRequests** : Remplacement systématique de la validation en ligne des contrôleurs par des classes FormRequest dédiées pour une sécurité et une robustesse de type accrues.
- **Renforcement de l'API** : Amélioration des règles de validation pour `PushSubscription`, `WorkoutLine`, et `DailyJournal`.

## [1.4.12] - 2026-02-26

### Ajouté

- **CRUD Succès** : Implémentation du support backend complet pour la création, la lecture, la mise à jour et la suppression des succès (achievements) des utilisateurs.
- **Tests E2E** : Introduction de tests E2E complets pour les séances d'entraînement couvrant l'intégralité du flux d'entraînement.

## [1.4.11] - 2026-02-20

### Modifié

- **UI Liquid Glass** : Refactorisation de `InputLabel` et de plusieurs composants de formulaire pour adhérer strictement au système de conception Liquid Glass.

### Optimisé

- **Performance** : Optimisation des requêtes d'historique de volume et amélioration de l'indexation de la base de données pour le tableau de bord des statistiques.

## [1.4.10] - 2026-02-15

### Corrigé

- **Dépendances Frontend** : Résolution de conflits avec Inertia.js et les paquets de base de Vue 3.
- **Formatage** : Unification du style de code dans toute l'application à l'aide de Laravel Pint et Prettier.

## [1.4.9] - 2026-02-10

### Corrigé

- **Tableau de bord Pulse** : Implémentation d'un correctif architectural définitif pour les conflits de politique de sécurité du contenu (CSP) en utilisant `ConditionalCspHeaders`.
- **GitHub Actions** : Correction du label du runner ARM64 en `ubuntu-24.04-arm`, résolvant le blocage dans la CI.

### Modifié

- **Stratégie Multi-Arch** : Passage à une stratégie de build parallèle et de fusion de manifestes.

### Optimisé

- **Performance du build Docker** : Refactorisation du workflow CI pour exploiter les runners natifs ARM64, réduisant les temps de build de ~85 %.
- **Stratification du Dockerfile** : Implémentation de `--platform=$BUILDPLATFORM` pour les étapes de build et copie granulaire pour une meilleure utilisation du cache.

## [1.4.8] - 2026-02-10

### Obsolète

- Cette version contenait un label de runner GitHub Actions incorrect et une configuration CSP conflictuelle. Les utilisateurs doivent passer à la v1.4.9 immédiatement.

## [1.4.7] - 2026-02-10

### Corrigé

- **Correctif Production** : Suppression de l'option `--force` non supportée de `filament:upgrade` dans `entrypoint.sh` pour éviter un crash du serveur.

## [1.4.6] - 2026-02-10

### Ajouté

- **SyncService** : Introduction d'une logique de synchronisation centralisée pour préparer le support complet hors ligne.

### Modifié

- **Migration Axios** : Migration des interactions d'entraînement et des préférences de notification de profil vers Axios pour une communication API robuste.
- **Rector & Pint** : Application de la modernisation automatisée du code et imposition du style dans toute la base de code.

### Corrigé

- **Correctif Production** : Résolution de l'échec critique du démarrage du serveur causé par le chargement de Telescope en production.
- **Stabilité CI** : Correction des échecs de test Dusk (pages blanches) en isolant le conflit des actifs Vite.

## [1.4.5] - 2026-02-05

### Ajouté

- **Swipe-to-Action** : Intégration de `SwipeableRow` pour les séries (glisser à gauche pour supprimer, à droite pour dupliquer).
- **Chronomètre intelligent** : Ajout d'un chronomètre de repos intelligent avec retour haptique.
- **Moteur haptique** : Retour tactile pour la complétion des gestes et les événements du chronomètre.
- **Thèmes dynamiques** : Ajout d'un moteur de mode sombre/clair avec synchronisation des préférences système.

### Sécurité

- **Correction IDOR** : Prévention de l'association d'exercices non autorisée dans les objectifs/PR.
- **Assignation de masse** : Renforcement des modèles de statistiques utilisateur contre les mises à jour non autorisées.

### Modifié

- **Optimisation Bolt** : Réduction de la taille de la charge utile du tableau de bord et optimisation de l'invalidation du cache.

### Corrigé

- **Correction N+1** : Optimisation de `PersonalRecordService` pour charger à l'avance les relations entraînement/exercice (#395).
- **SetsController** : Correction de `TypeError` (#393).
- **Modal.vue** : Correction de `TypeError` dans la phase de démontage pour iOS (#394).
- **Audit Larastan** : Résolution des échecs dans le service de synchronisation des PR.

## [1.4.0] - 2026-01-30

### Ajouté

- **Offline-first** : Implémentation de la synchronisation hors ligne avec Workbox et Dexie.

### Sécurité

- **MFA** : Ajout de l'authentification multi-facteurs pour l'administration Filament.
- **CSP** : Renforcement de la politique de sécurité du contenu pour les routes du backoffice.

### Modifié

- **Ops** : Stabilisation des retours en arrière (rollbacks) de migration pour SQLite/CI.
- **PWA & Mobile** : Affinement des marges de sécurité mobile pour une ergonomie supérieure.

## [1.3.1] - 2026-01-24

### Corrigé

- **Notifications** : Correction de `TypeError` sur le compte des notifications mises en cache.
- **PHP 8.4** : Résolution des avertissements d'obsolescence (constantes PDO).

## [1.3.0] - 2026-01-21

### Ajouté

- **Suivi des habitudes** : Implémentation complète de la création, de la journalisation et de la visualisation des habitudes.
- **Signes vitaux** : Nouveaux modules pour le suivi de la fréquence cardiaque, de la tension artérielle et de la graisse corporelle.

### Sécurité

- **Qualité** : Atteinte de la conformité Larastan Niveau 8.

### Modifié

- **UI Liquid Glass** : Implémentation du système de conception sur toutes les pages.
- **Style** : Application d'une couverture de style Laravel Pint à 100 %.

### Optimisé

- **Performance** : Optimisation des modèles de requêtes de base de données.

### Corrigé

- **iOS Safari** : Résolution des décalages de mise en page mobile.
- **Dates** : Correction de l'alignement de l'analyse des dates entre l'API et le Frontend.

## [1.2.0] - 2026-01-15

### Ajouté

- Système de modèles d'entraînement.
- Outil de calcul de disques.

### Optimisé

- **Performance** : Optimisations (mise en cache, chargement avide).

### Sécurité

- **Renforcement** : Limitation du débit, validation des entrées.

### Modifié

- **Cache Stats** : Les statistiques du tableau de bord sont désormais mises en cache pendant 60 secondes.
- **Cache Exercices** : La liste des exercices est mise en cache pendant 10 minutes.

### Corrigé

- **AchievementService** : Correction des requêtes N+1.
- **Indexation** : Ajout des index manquants sur les colonnes fréquemment interrogées.

## [1.1.0] - 2026-01-10

### Ajouté

- Système de suivi des records personnels (PR).
- Système de succès/trophées avec célébrations.
- Compteur de série pour les jours d'entraînement consécutifs.
- Suivi des mesures corporelles.
- Fonctionnalité de journal quotidien.
- Objectifs personnalisés avec suivi de la progression.
- Notifications Web Push.

### Modifié

- **Tableau de bord** : Design repensé avec des statistiques rapides.
- **Navigation** : Amélioration de la navigation mobile.

## [1.0.0] - 2026-01-01

### Ajouté

- Sortie initiale.
- Authentification utilisateur (email + OAuth via Google, GitHub, Apple).
- Gestion des séances d'entraînement.
- Bibliothèque d'exercices avec catégories.
- Journalisation des séries et répétitions.
- Historique des entraînements.
- Statistiques de base.
- Design PWA axé sur le mobile.

[Unreleased]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.18...HEAD
[1.4.18]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.14...v1.4.18
[1.4.14]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.13...v1.4.14
[1.4.13]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.12...v1.4.13
[1.4.12]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.11...v1.4.12
[1.4.11]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.10...v1.4.11
[1.4.10]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.9...v1.4.10
[1.4.9]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.8...v1.4.9
[1.4.8]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.7...v1.4.8
[1.4.7]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.6...v1.4.7
[1.4.6]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.5...v1.4.6
[1.4.5]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.4.0...v1.4.5
[1.4.0]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.3.1...v1.4.0
[1.3.1]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/kuasar-mknd/gym-tracker/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/kuasar-mknd/gym-tracker/releases/tag/v1.0.0

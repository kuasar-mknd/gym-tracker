# 🏋️ Gym Tracker - Product Roadmap

> Roadmap complète des fonctionnalités à implémenter pour transformer Gym Tracker en une application fitness de niveau professionnel.

---

## 📊 État actuel (v1.0)

- [x] Authentification utilisateur (Laravel Breeze)
- [x] Login social (Socialite)
- [x] Gestion des séances (Workouts)
- [x] Ajout d'exercices avec séries/répétitions
- [x] Suivi des mensurations corporelles
- [x] CRUD complet des exercices
- [x] Dashboard avec graphiques (Chart.js)
- [x] UI mobile-first Glass Design
- [x] CI/CD avec GitHub Actions

---

## 🚀 Phase 1 : Core Fitness Features (Priorité Haute)

### 1.1 Personal Records (PR) Automatiques

- [x] Créer table `personal_records` (user_id, exercise_id, type, value, achieved_at)
- [x] Service `PersonalRecordService` pour détecter automatiquement les PRs
- [x] Types de PR : max weight, max reps, max volume, max 1RM estimé
- [x] Notifications toast quand un PR est battu
- [x] Affichage des PRs sur la page exercice
- [x] Badge/icône PR sur les sets qui sont des records
- [x] Historique des PRs par exercice

### 1.2 Workout Templates (Modèles de séances)

- [x] Créer tables `workout_templates`, `workout_template_lines`, `workout_template_sets`
- [x] Interface de gestion des modèles (Index, Create)
- [x] Lancer une séance à partir d'un modèle
- [x] Sauvegarder une séance existante comme modèle
- [x] Prise en compte de l'ordre des exercices et des séries
- [x] Tests unitaires et de feature pour les modèles

### 1.3 Rest Timer (Chronomètre de repos)

- [x] Lancement automatique du chrono après validation d'un set
- [x] Réglage du temps de repos par défaut (Global & par exercice)
- [x] Notification sonore/vibration à la fin du chrono
- [x] Contrôles manuels (Start/Stop/Reset/+30s)
- [x] Affichage flottant ou persistant pendant le repos

### 1.4 Volume & Statistiques avancées

- [x] Calcul du volume total par séance (poids × reps × séries)
- [x] Visualisation du volume par groupe musculaire (Pie chart)
- [x] Graphique d'évolution du 1RM estimé par exercice
- [x] Total tonnage soulevé par mois
- [x] Comparaison des performances semaine après semaine
- [x] Intégration de `chart.js` pour les visualisations

---

## ⚡ Phase 2 : Expérience Utilisateur (Priorité Moyenne-Haute)

### 2.1 Progressive Web App (PWA)

- [x] Installer `silviolleite/laravelpwa` ou config manuelle (Vite PWA)
- [x] Manifest.json avec icônes et couleurs
- [x] Service Worker pour cache offline
- [x] Possibilité d'installer l'app sur mobile
- [x] Sync des données quand connexion retrouvée

### 2.2 Notifications & Rappels

- [x] Configurer Laravel Queue avec database driver
- [x] Table `notification_preferences` par utilisateur
- [x] Rappels d'entraînement programmables
- [x] Notifications de félicitations (PR, streak, milestone)
- [x] Notifications push (Web Push API implémenté)
- [ ] Email digest hebdomadaire (optionnel)

### 2.3 Photos de progression

- [ ] Installer `spatie/laravel-medialibrary`
- [ ] Upload de photos avec date
- [ ] Comparaison avant/après côte à côte
- [ ] Galerie chronologique
- [ ] Photos privées par défaut

### 2.4 Export & Rapports

- [ ] Installer `barryvdh/laravel-dompdf`
- [ ] Export PDF résumé de séance
- [ ] Export PDF rapport mensuel
- [ ] Installer `maatwebsite/excel`
- [ ] Export CSV/Excel des données
- [ ] Import de données depuis autres apps

---

## 🎯 Phase 3 : Gamification & Motivation

### 3.1 Système d'objectifs (Goals)

- [x] Créer table `goals` (type, target, current, deadline)
- [x] Types : poids soulevé, fréquence, volume, mensuration
- [x] Progression visuelle vers l'objectif
- [x] Célébration quand objectif atteint

### 3.2 Achievements & Badges

- [x] Créer tables `achievements` et `user_achievements`
- [x] Badges automatiques :
    - [x] "First Workout" - Première séance
    - [x] "Week Warrior" - 3 séances (originalement 5, ajusté)
    - [x] "Streak Master" - 3 jours consécutifs (original 30)
    - [x] "Heavy Lifter" - Premier PR à 100kg
    - [x] "Volume King" - 5 tonnes soulevées
- [x] Page avec tous les badges débloqués/verrouillés

### 3.3 Streaks & Consistance - [x] Completed

- [x] Calcul du streak actuel (jours consécutifs)
- [x] Streak le plus long
- [ ] Calendrier type GitHub contributions (jours actifs) - Reporté
- [ ] Rappel si le streak risque d'être cassé

---

## 🌐 Phase 4 : Social & Communauté (Optionnel)

### 4.1 Profil public

- [ ] Option profil public/privé
- [ ] Page profil avec stats résumées
- [ ] URL personnalisée (/u/username)

### 4.2 Partage de séances

- [ ] Bouton "Partager" sur une séance
- [ ] Lien public pour voir une séance
- [ ] Copier un workout d'un autre utilisateur

### 4.3 Leaderboards

- [ ] Classement par exercice (optionnel opt-in)
- [ ] Classement volume hebdomadaire
- [ ] Catégories par poids de corps

### 4.4 Friends & Following

- [ ] Système de follow
- [ ] Feed d'activité des amis
- [ ] Challenges entre amis

---

## 🔧 Phase 5 : Infrastructure & Performance

### 5.1 API REST complète - [x] Completed

- [x] Installer `spatie/laravel-query-builder`
- [x] API Resources pour tous les modèles
- [x] Documentation Swagger/OpenAPI (Structure en place)
- [x] Versioning API (v1)
- [x] Rate limiting par endpoint

### 5.2 Performance & Caching

- [x] Configurer Redis
- [x] Cache des stats dashboard (5-15 min TTL)
- [x] Cache des personal records
- [x] Eager loading optimisé
- [x] Installer Laravel Telescope (dev only)

- [x] **Background Jobs** (Phase 5.3) : Laravel Horizon, Jobs asynchrones pour Stats/Goals/Achievements.

- [ ] Configurer Laravel Horizon
- [ ] Jobs pour calcul de stats lourdes
- [ ] Jobs pour envoi de notifications
- [ ] Jobs pour génération de rapports

### 5.4 Scheduled Tasks

- [ ] Rappels d'entraînement quotidiens
- [ ] Calcul des streaks à minuit
- [ ] Nettoyage des séances vides anciennes
- [ ] Email digest hebdomadaire (dimanche soir)

---

## 📱 Phase 6 : Intégrations externes

### 6.1 Wearables & Apps

- [ ] Import depuis Apple Health (via CSV/API)
- [ ] Import depuis Google Fit
- [ ] Sync avec montres Garmin/Fitbit

### 6.2 Nutrition (extension future)

- [ ] Tracker de calories basique
- [ ] Objectifs macros
- [ ] Intégration MyFitnessPal API

---

## 🛠 Améliorations techniques continues

### Code Quality

- [ ] Augmenter couverture de tests à 80%+
- [ ] Ajouter tests E2E avec Laravel Dusk
- [ ] Documenter l'API avec Scribe
- [ ] Ajouter PHPStan level 8

### Sécurité

- [ ] Audit de sécurité
- [ ] 2FA (Two-Factor Authentication)
- [ ] Rate limiting sur login
- [ ] GDPR : export/suppression des données

### DevOps

- [ ] Docker compose pour dev local
- [ ] Staging environment
- [ ] Monitoring avec Sentry/Bugsnag
- [ ] Backups automatiques

---

## 📦 Packages à installer

```bash
# Phase 1
# (aucun package externe nécessaire)

# Phase 2
composer require silviolleite/laravelpwa
composer require spatie/laravel-medialibrary
composer require barryvdh/laravel-dompdf
composer require maatwebsite/excel

# Phase 3
# (aucun package externe nécessaire)

# Phase 4
# (aucun package externe nécessaire)

# Phase 5
composer require spatie/laravel-query-builder
composer require laravel/horizon
composer require laravel/telescope --dev

# DevOps
composer require spatie/laravel-backup
composer require sentry/sentry-laravel
```

---

## 🗓 Timeline suggérée

| Phase   | Durée estimée | Priorité    |
| ------- | ------------- | ----------- |
| Phase 1 | 2-3 semaines  | 🔴 Critique |
| Phase 2 | 2-3 semaines  | 🟠 Haute    |
| Phase 3 | 1-2 semaines  | 🟡 Moyenne  |
| Phase 4 | 2-3 semaines  | 🟢 Basse    |
| Phase 5 | 1-2 semaines  | 🟠 Continue |
| Phase 6 | Variable      | 🟢 Future   |

---

## 🎯 MVP+ (Prochaine release)

Pour la prochaine version majeure, focus sur :

1. ✨ **Personal Records automatiques**
2. ⏱️ **Rest Timer**
3. 📊 **Volume calculator**
4. 📱 **PWA installable**

---

_Dernière mise à jour : 2026-01-13_

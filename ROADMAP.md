# GymTracker Roadmap

## 🎯 Vision

Faire de GymTracker la meilleure application mobile-first de suivi de musculation.

---

## ✅ Complété

### v1.0 — Core Features

- [x] Système d'authentification (email + OAuth)
- [x] Création et gestion de séances
- [x] Enregistrement des exercices, séries, répétitions
- [x] Historique des entraînements
- [x] Statistiques de base
- [x] Design "Liquid Glass" mobile-first
- [x] PWA installable

### v1.1 — Enhanced Tracking

- [x] Records personnels (PR) automatiques
- [x] Système d'achievements/trophées
- [x] Streak counter (jours consécutifs)
- [x] Mesures corporelles
- [x] Journal quotidien
- [x] Objectifs personnalisés
- [x] Notifications push (Web Push)

### v1.2 — Polish

- [x] Modèles de séances
- [x] Calculateur de plaques
- [x] Graphiques de progression (Chart.js)
- [x] Optimisation des performances (cache, eager loading)
- [x] Audit de sécurité

---

## 🚧 En Cours

### v1.3 — Amélioration UX & Features (Complété)

- [x] Habits Tracking
- [x] Vitals (Cardio/Blood Pressure)
- [x] Body Fat Calculator
- [x] Mode sombre/clair toggle (Via système Liquid Glass)
- [x] Animations micro-interactions

---

## 📅 Planifié

### v1.4 — Mobile Premium UX (Complété ✅)

- [x] Smart Rest Timer (existait déjà)
- [x] Gestes Tactiles (SwipeableRow)
- [x] Sélecteur de Thème (useTheme + ThemeToggle)
- [x] Micro-animations (v-press, CheckAnimation)
- [x] Optimistic UI (toggle set)
- [x] Haptic Feedback (useHaptics)

### v1.4.1 — UX Polish & Dark Mode (Prioritaire)

- **🔴 Critique** :
    - [x] Dark mode sur tous les composants (actuellement seul ThemeToggle fonctionne)
    - [ ] Fixer Modal.vue pour mode clair
    - [ ] Keyboard avoidance pour inputs
- **🟠 UX Incomplète** :
    - [ ] SwipeableRow sur listes workouts/exercises
    - [ ] Haptic feedback sur navigation et formulaires
    - [ ] Optimistic UI pour add/delete/update sets
- **🟡 Améliorations** :
    - [ ] Pull-to-refresh sur pages principales
    - [ ] Skeleton loading (utiliser .glass-skeleton)
    - [ ] Transitions de page (Inertia)
    - [ ] Empty states avec illustrations

### v1.5 — Social & Exports

- [ ] Export PDF des statistiques mensuelles.
- [ ] Partage de séance via lien dynamique.

### v2.0 — Offline & Sync

- [ ] Mode hors-ligne complet
- [ ] Synchronisation background
- [ ] Service Worker amélioré
- [ ] Conflict resolution

### v2.1 — Social Features

- [ ] Partage de workouts
- [ ] Leaderboards
- [ ] Challenges entre amis
- [ ] Feed d'activité

### v2.2 — Analytics Pro

- [ ] Export PDF des statistiques
- [ ] Comparaison mois par mois
- [ ] Prédictions IA de progression
- [ ] Recommandations d'exercices

### v3.0 — Intégrations

- [ ] Apple HealthKit
- [ ] Google Fit
- [ ] Garmin Connect
- [ ] Strava

---

## 💡 Idées Futures

- Application native iOS/Android (Capacitor ou native)
- API publique pour intégrations tierces
- Abonnement premium avec features avancées
- Coaching IA personnalisé
- Plans d'entraînement générés

---

## 🗳️ Suggest a Feature

Ouvre une [Feature Request](https://github.com/kuasar-mknd/gym-tracker/issues/new?template=feature_request.md) sur GitHub !

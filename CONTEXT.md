# Gym Tracker

A fitness tracking application (workouts, habits, goals, body measurements, supplements) with a Laravel backend and multiple clients: an existing PWA web app and a future native mobile app.

## Language

**Workout**:
A recorded training session, the core unit of the domain. Contains one or more workout lines.
_Avoid_: session, training, séance

**Workout line**:
An exercise entry within a workout, grouping the sets performed for that exercise.

**Set**:
A single group of repetitions for one exercise within a workout line (weight × reps).

**Workout template**:
A reusable workout definition that can be instantiated as a workout.
_Avoid_: plan, routine

**Exercise**:
A movement in the exercise library that can be performed in workouts.

**Personal record (PR)**:
The best performance achieved for an exercise, tracked over time.

**Habit**:
A recurring behaviour the user tracks with daily logs.
_Avoid_: routine, streak (a streak is a derived metric, not a stored entity)

**Goal**:
A user-defined target (weight, measurement, PR) with a deadline.

**Body measurement**:
A physical measurement (weight, body part circumference) recorded over time.

**PWA**:
The existing installable web app client (Vue + Inertia), kept as the web product.

**Mobile app**:
The native-capable client (Capacitor shell + embedded SPA) planned for iOS, with a future watchOS companion.
_Avoid_: native app, iOS app

**Offline-first**:
The mobile app's data model: fully functional without network, resynchronizing automatically when connectivity returns.
_Avoid_: offline mode (implies a degraded state; offline is the default)

**Sync**:
The reconciliation of local client data with the server (pull of changes, push of queued writes).

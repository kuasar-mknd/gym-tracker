# Native mobile client (Capacitor + dedicated SPA) alongside the PWA

The app needed iOS push notifications, native features (cardio/HealthKit), full offline operation, and a future watchOS companion. We decided to build a **new dedicated mobile client**: a Capacitor shell embedding a new Vue SPA that consumes the existing REST API (`/api/v1`, Sanctum). The existing Inertia PWA stays as the web product.

## Considered Options

- **De-Inertia-ising the existing Vue pages** to embed them in Capacitor — rejected: invasive refactor of all 14 sections, risk of breaking the working PWA, and Inertia's server-driven model conflicts with offline-first.
- **Fully native Swift (iOS + watchOS)** — rejected: rewrites the entire UI from scratch, discards the existing Vue base, and makes the web/PWA a separate maintenance track.
- **Flutter / React Native** — rejected: no viable watchOS story, new toolchain, no reuse of the existing Vue component base.

## Consequences

- Two clients to maintain (PWA + mobile SPA); purely visual components (charts, forms) can be shared via a common component library.
- The API must grow offline-first support: client-generated IDs, `updated_at`-based delta sync, write queue semantics.
- A paid Apple Developer account is required for push notifications and any future watchOS app.
- Offline scope is phased: v1 = daily-entry entities (workouts/sets, habits, supplements, water, journal) read/write offline, rest read-cached; v2 extends write-offline to the rest.
- Sync conflict rule: last-write-wins (v1).

## Amendement du 2026-09-03 — l'API v1 n'est pas ce contrat

L'API REST complète (28 ressources, 144 routes, authentifiée par la session) n'a jamais eu de client : la PWA n'en appelait que sept écritures (`sets` store/update/destroy, `workout-lines` store/destroy/set-order, `workouts` line-order). Elle a été ramenée à ces sept routes (#1673), la spec OpenAPI et `l5-swagger` sont partis avec elle, et la vérification CSRF s'applique désormais à ce qui reste.

Le client mobile décrit ci-dessus repartira donc d'un **contrat explicite**, écrit pour lui : jetons Sanctum (aucun `createToken()` n'existe aujourd'hui), identifiants générés côté client et synchronisation par delta comme prévu plus haut. Rien de l'ancienne API n'est à réutiliser tel quel.

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

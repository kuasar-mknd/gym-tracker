---
paths:
  - 'app/Filament/**'
---

# Filament

## Une ressource ou page nouvelle du panneau s'ouvre au super administrateur par la liste des capacités, pas par shield:generate
Rien ne lance `shield:generate` au déploiement et `super_admin.define_via_gate` est faux : une ressource ajoutée n'a ses permissions Shield nulle part en production, et le super administrateur ne la voit pas. Le chemin qui marche (sauvegardes, santé, exceptions, tâches planifiées) : la policy vérifie `$authUser->can('ViewAny:Modele')` comme `ActivityLogPolicy`, ET les capacités (`ViewAny:Modele`, `View:Modele`, …) sont ajoutées à `ouvrirLesOutilsAuSuperAdministrateur()` dans `AppServiceProvider`, qui les définit par `Gate::define` pour le rôle super_admin ; une permission Shield accordée à un autre rôle marche toujours. Une page de greffon sans policy passe par `->authorize()` du greffon sur la même capacité. Toute route hors panneau sous `/backoffice` (Pulse, journaux) porte `Filament\Http\Middleware\Authenticate` (un invité est renvoyé vers la connexion du panneau, ce que `BackofficeAuthorizationTest` exige de chaque route GET sous ce préfixe), `IpWhitelist`, puis la porte de l'outil ; son préfixe de nom de route s'ajoute à `isThirdPartyPanel()` de `NoOrphanedFeatureTest`. Dans un test, un seul `actingAs` par cas : changer d'administrateur dans le même cas fait déconnecter la session du panneau (302).

# Sécurité

Cette page dit ce que l'application garantit en matière de sécurité, et comment fonctionne la double authentification du panneau d'administration.

## Ce que l'application garantit

### Connexion par un fournisseur (OAuth)

- Google, GitHub et Apple, dès que l'identité OAuth du fournisseur est complète.
- Pour Apple, l'application signe elle-même le secret client à chaque échange, avec la clé `.p8`.
- Les variables à poser sont dans [la référence](variables-d-environnement.md#connexion-sociale).

### Protection des requêtes et des pages

- **Throttling** de l'API.
- **CSP strict** : un nonce neuf à chaque requête, Octane compris.
- La CSP n'admet d'autre origine que les services push des navigateurs.
- Le panneau, Horizon, Pulse et Telescope ne font rien charger à un autre site :
  - les logos de connexion sont servis par l'application ;
  - les avatars du panneau, de Pulse et de Telescope sont des initiales qu'elle dessine ;
  - Horizon, Pulse et Telescope s'affichent dans la police du système, sans leur fonte en ligne.
- La sonde de santé `/up` répond en JSON, sans page ni ressource d'un autre site.

### Compte ouvert par le lien

L'inscription et le changement d'adresse répondent de la même façon, dans le même temps, qu'une adresse ait déjà un compte ou non.

| Situation | Comportement |
| --- | --- |
| Inscription | Le compte ne s'ouvre qu'une fois suivi le lien envoyé à l'adresse. On suit le lien, puis on se connecte sur le même appareil. |
| Changement d'adresse | La nouvelle adresse ne remplace l'ancienne qu'une fois suivi le lien envoyé à cette adresse. |
| Adresse déjà prise | Son titulaire en est prévenu par courriel. |
| Compte jamais ouvert | Il s'efface au bout de sept jours. Les comptes créés avant ce changement, ou depuis le panneau, ne sont pas concernés. |

Le panneau montre les comptes en attente pour ce qu'ils sont :

- colonne « En attente du lien », dont le badge affiche la date de l'inscription ;
- filtre du même nom dans la liste des utilisateurs, et mention sur la fiche ;
- tuiles, graphique des inscriptions et encart « Derniers inscrits parmi les comptes ouverts » : ils ne lisent que les comptes ouverts ;
- tuile « Comptes » : elle dit combien de comptes attendent.

## Double authentification du panneau

Le panneau (`/backoffice`) exige la double authentification de chaque administrateur : une application de codes à usage unique (TOTP), et des codes de récupération.

Le mot de passe et la liste d'adresses (`ADMIN_ALLOWED_IPS`) ne suffisent pas : un super administrateur fixe le mot de passe de tout compte et télécharge les sauvegardes.

### Première connexion

Un administrateur qui n'a pas enrôlé son application entre avec son mot de passe. C'est le cas du premier administrateur, créé par `AdminSeeder`, et de tout compte créé depuis.

1. Le panneau le conduit à la page d'enrôlement, avant toute autre page.
2. Il scanne le QR code avec son application de codes.
3. Il saisit le code de l'application.
4. Il note les codes de récupération : ils ne s'affichent qu'une fois.

Les connexions suivantes demandent le code de l'application.

### Perte de l'application

À la connexion, « utiliser un code de récupération » remplace le code de l'application. Chaque code ne sert qu'une fois.

Les codes se régénèrent depuis le profil. Un administrateur enrôlé avant l'activation des codes de récupération doit le faire : il a un secret et aucun code, et son choix d'un code de récupération est refusé comme un code faux.

### Perte de l'application et des codes

Depuis le conteneur `app` :

```bash
php artisan admin:reinitialiser-mfa adresse@example.org
```

- La commande efface le secret et les codes de cet administrateur.
- À sa prochaine connexion, le panneau lui fait enrôler un nouveau secret.
- Elle n'ouvre pas de session et ferme celles qui existent : le panneau, Pulse, le lecteur de journaux et Horizon déconnectent l'administrateur à sa prochaine requête.
- Elle révoque les jetons de diagnostic que cet administrateur avait créés.

L'accès au serveur remplace donc le second facteur : à réserver à qui administre la pile.

### La session

La session porte la preuve du second facteur : « Se souvenir de moi » ne rouvre pas le panneau sans le code de l'application. Ce que cela change au déploiement est dans [la mise à niveau](mise-a-niveau.md#double-authentification-obligatoire-au-panneau).

### Hors du panneau

Pulse, le lecteur de journaux et Horizon (par le panneau) se ferment aussi à un administrateur sans double authentification, même quand une permission Shield du même nom les lui accorde (`Gate::before` de `AppServiceProvider`, posé avant celui de Spatie). Sans cela, son mot de passe seul les aurait ouverts avant l'enrôlement.

### Tests

- `Admin::factory()` enrôle une application.
- `->sansDoubleAuthentification()` rend un administrateur qui n'y est pas passé.
- `->avecCodesDeRecuperation([...])` lui donne des codes.

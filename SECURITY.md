# Politique de Sécurité

## Versions Supportées

| Version | Supportée           |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |
| < 1.0   | :x:                |

## Signalement d'une Vulnérabilité

Nous prenons la sécurité très au sérieux. Si vous découvrez une vulnérabilité de sécurité, merci de la signaler de manière responsable.

### Comment signaler

**⚠️ N'ouvrez pas d'issue GitHub publique pour une vulnérabilité de sécurité.** Une issue est visible de tous dès sa création, y compris de quelqu'un qui chercherait à exploiter la faille avant qu'elle ne soit corrigée.

Utilisez le **signalement privé de GitHub** :

**[→ Signaler une vulnérabilité](https://github.com/kuasar-mknd/gym-tracker/security/advisories/new)**

Ou depuis le dépôt : onglet **Security** → **Report a vulnerability**.

Le canal est actif et vérifié. Le rapport n'est visible que des mainteneurs, et le fil de discussion reste privé jusqu'à la publication de l'avis.

Incluez dans votre rapport :

- Description de la vulnérabilité
- Étapes pour reproduire
- Impact potentiel
- Suggestions de correction (optionnel)

### À quoi s'attendre

1. **Accusé de réception** — Nous confirmerons la réception sous 48 heures
2. **Investigation** — Nous enquêterons et vous tiendrons informé
3. **Correction** — Nous développerons et testerons un correctif
4. **Divulgation** — Nous coordonnerons la divulgation avec vous
5. **Crédit** — Nous vous citerons dans les notes de version (si souhaité)

### Périmètre

Les éléments suivants sont dans le périmètre :

- Contournement d'authentification ou d'autorisation
- Injection SQL
- XSS (Cross-Site Scripting)
- CSRF (Cross-Site Request Forgery)
- Exposition de données sensibles
- Server-side request forgery (SSRF)
- Exécution de code à distance (RCE)
- **Contournement des limitations de débit** sur l'authentification, le changement de mot de passe ou le back-office

### Hors périmètre

- Déni de Service (DoS)
- Ingénierie sociale
- Sécurité physique
- Problèmes dans les dépendances (signaler en amont au mainteneur concerné, puis nous prévenir si l'application est exposée)

## Ce qui est déjà en place

Pour éviter les rapports en doublon, voici les protections actives et vérifiées en intégration continue :

| Mesure | Mise en œuvre |
| --- | --- |
| En-têtes de sécurité | `SecurityHeaders` — HSTS avec preload, `X-Frame-Options`, `nosniff`, `Referrer-Policy`, COOP, Permissions-Policy |
| CSP | spatie/laravel-csp, avec nonce par requête et politiques dédiées |
| Limitation de débit | connexion (5/min par IP + identifiant), double authentification, confirmation de mot de passe, back-office (30/min) + liste blanche d'IP |
| Autorisation | Policies par modèle, garde de type sur l'identité authentifiée, tests des deux versants pour chaque règle |
| SSRF | Endpoint des notifications push restreint à https et aux hôtes publics, résolution DNS vérifiée |
| Analyse statique | Semgrep (`p/default`, `p/php`, `p/owasp-top-ten`), PHPStan, CodeQL |
| Secrets | TruffleHog à chaque exécution, secret scanning et push protection GitHub |
| Dépendances | `composer audit`, `npm audit`, Dependabot, détection des dépendances inutilisées |
| Tests | 1800+ tests, dont une vingtaine de fichiers dédiés à la sécurité, avec un seuil de couverture bloquant |

## Bonnes Pratiques de Sécurité

Lors de vos contributions :

- Ne jamais commiter de secrets ou d'identifiants
- Utiliser les variables d'environnement pour les configurations sensibles
- Valider et assainir toutes les entrées utilisateur
- Utiliser des requêtes préparées pour la base de données
- Suivre les bonnes pratiques de sécurité Laravel

---

Merci de nous aider à garder GymTracker sécurisé ! 🔒

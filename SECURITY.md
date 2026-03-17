# Politique de Sécurité

## Versions Supportées

| Version | Supportée           |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |
| < 1.0   | :x:                |

## Signalement d'une Vulnérabilité

Nous prenons la sécurité très au sérieux. Si vous découvrez une vulnérabilité de sécurité, merci de la signaler de manière responsable.

### Comment signaler

**⚠️ Ne pas ouvrir d'issue GitHub publique pour des vulnérabilités de sécurité.**

À la place, merci de nous contacter via : [GitHub Issues](https://github.com/kuasar-mknd/gym-tracker/issues)

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

- Contournement d'authentification
- Injection SQL
- XSS (Cross-Site Scripting)
- CSRF (Cross-Site Request Forgery)
- Exposition de données sensibles
- Server-side request forgery (SSRF)
- Exécution de code à distance (RCE)

### Hors périmètre

- Problèmes de limitation de débit (rate limiting)
- Déni de Service (DoS)
- Ingénierie sociale
- Sécurité physique
- Problèmes dans les dépendances (signaler en amont au mainteneur concerné)

## Bonnes Pratiques de Sécurité

Lors de vos contributions :

- Ne jamais commiter de secrets ou d'identifiants
- Utiliser les variables d'environnement pour les configurations sensibles
- Valider et assainir toutes les entrées utilisateur
- Utiliser des requêtes préparées pour la base de données
- Suivre les bonnes pratiques de sécurité Laravel

---

Merci de nous aider à garder GymTracker sécurisé ! 🔒

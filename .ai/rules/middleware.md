---
paths:
  - 'bootstrap/app.php, app/Http/Middleware/**'
---

# Middleware

## Les en-têtes d'une réponse tiennent dans 4 Kio, sinon le proxy de DSM rend un 502
Le nginx du proxy inverse de DSM lit tous les en-têtes d'une réponse dans un tampon de 4 Kio (`proxy_buffer_size` par défaut, que son gabarit ne change pas). Au-delà : « upstream sent too big header », 502, et la page DSM « Désolé, la page que vous recherchez est introuvable » — jamais celle de l'application. `AddLinkHeadersForPreloadedAssets` a franchi la limite en septembre 2026 : son en-tête `Link` listait chaque morceau préchargé avec URL absolue et nonce (2 293 octets sur l'accueil, 4 355 au total). Seuls les chargements complets le portaient, pas les navigations Inertia : l'application marchait en naviguant et bloquait la PWA à l'ouverture, cookie « se souvenir de moi » aidant (#1902). Ne pas le remettre ; tout nouvel en-tête ou cookie se mesure contre `tests/Feature/EnTetesDeReponseTest.php`, qui tient un budget de 3 Kio par page complète et par réponse de connexion (trois cookies, la plus chargée). Seuls les en-têtes qui recopient l'URL demandée restent sans borne — le `Location` de `redirect()->intended()`, le `X-Inertia-Location` d'un 409 : il faut une URL de plus de 2 Kio pour les faire déborder, ce qu'aucune page de l'application ne produit.

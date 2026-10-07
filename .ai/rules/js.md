---
paths:
  - 'resources/js/**'
  - 'resources/js/**/*.vue'
  - 'resources/css/**'
---

# Js

## Une couleur ne s'écrit que dans app.css — un composant nomme un rôle
Aucune couleur brute dans `resources/` : ni nuance Tailwind (`bg-slate-800`), ni hexadécimal, ni `rgba()`. Tout vient d'un jeton de `resources/css/app.css`. Huit gardes dans `tests/Feature/Conventions/` le tiennent, dont deux interdictions sèches.

Le piège principal : **ne choisis jamais toi-même la couleur du texte posé sur un fond**. Un jeton de texte unique ne peut pas convenir — l'orange porte du blanc à 4,7:1 et de l'encre à 3,8:1, le vert d'état exactement l'inverse. Emploie un utilitaire apparié (`accent-fill`, `state-fill`, `info-fill`, `danger-fill`, `category-fill-*`, `plate-fill-*`) : il pose le fond ET son texte.

Deux pièges de conversion, appris à nos dépens :
- remplacer une nuance par un rôle change la **luminosité**, pas seulement le nom. `emerald-500` → `accent-state` fait passer un texte blanc de 2,5:1 à 1,2:1 ;
- `bg-red-50 text-red-600` est un **couple lavis/texte**, pas un rôle écrit deux fois. Utilise l'opacité (`bg-accent-danger/10 text-accent-danger-deep`).

Le JavaScript lit les jetons par `Utils/couleurs.js` (`jeton()`, `jetonTransparent()`), jamais une valeur recopiée. Côté PHP — courriels, pages d'erreur, widgets Filament — c'est `App\Support\Charte`.

`@theme static` est obligatoire dans `app.css` : Tailwind n'émet sinon que les variables qu'une classe utilise, et les jetons lus uniquement par le JS sont absents du CSS compilé (les graphiques dessinent alors en noir).

## Un bouton passe par son composant, jamais par du markup recopié
Trois composants couvrent l'essentiel : `GlassButton` (action avec libellé), `GlassIconButton` (action réduite à une icône) et `GlassBigNumber` (le grand champ chiffré des calculateurs). Écrire un `<button>` à la main, c'est perdre la cible de 44 px, l'anneau de focus, l'état de chargement, et surtout la capacité de suivre la charte quand elle bouge — c'est ainsi que le « + » de la bibliothèque s'est retrouvé en dégradé alors que son jumeau desktop était en `primary`.

Trois règles se tiennent dans `tests/js/conventions/buttonVariants.test.js` et `touchTargets.test.js` :
- tout `type="submit"` DÉCLARE sa variante (l'absence retombe sur `default`, le verre pâle des actions tertiaires) ;
- « Annuler » est toujours `secondary`, jamais `ghost` : refuser et confirmer ne doivent pas se ressembler ;
- toute création (« Ajouter », « Créer », « Nouveau », l'icône `add`) est `primary` ;
- un bouton réduit à une icône atteint 44 px, par `min-h-touch`, par une taille explicite, ou par un `before:-inset-*` qui déborde sans pousser ses voisins.

Sur `GlassSelect`, `placeholder` est une INVITE (rendue `disabled`) et `empty-label` est un CHOIX vide sélectionnable. Les confondre donnait un « — Aucune — » sur lequel personne ne pouvait revenir.

## Glisser-déposer au doigt : les quatre pièges, tous payés au prix fort

`@formkit/drag-and-drop` saisit au PREMIER mouvement sans regarder la direction — elle n'expose aucun seuil directionnel. Une rangée qui doit aussi glisser latéralement ne peut donc pas arbitrer par l'espace : c'est le temps qui tranche (`longPress`, 220 ms). Et `longPress` ne marche que si le geste ne peut pas devenir un défilement : la bibliothèque appelle `preventDefault` sur le `pointerdown` APRÈS son minuteur, ce qui n'annule pas un défilement déjà engagé sur iOS.

Elle pose `draggable` sur le NODE — l'enfant direct du conteneur, donc la racine du composant de rangée, pas le div qu'on habille. Au doigt, iOS s'en saisit après une demi-seconde et fabrique son propre aperçu, qui vole le geste. `-webkit-user-drag` est une impasse et non un correctif mal placé : `caniuse-lite` le donne `"n"` sur Safari iOS pour TOUTES les versions, alors que l'API de glisser HTML5 y est `"y"` depuis la 15 — donc `dragstart` est bien émis et annulable. Une media query est une impasse pour une autre raison : elle décrit l'appareil quand le défaut décrit un geste, et un iPad au trackpad se déclare pointeur fin tout en restant tactile. La coupure se fait sur le type du dernier `pointerdown`, dans `useListeReordonnable.js`. Ne pas passer `nativeDrag: false` : la souris de bureau n'a QUE ce chemin, le chemin synthétique se retirant pour elle.

Elle déplace aussi le nœud elle-même sur le chemin tactile. Vue, restée sur l'ancien arrangement, écrit ensuite les numéros dans les mauvaises rangées. On reconstruit les rangées par une génération portée dans la clef du `v-for`.

Corollaire de vérification, le plus coûteux : jsdom ne rejoue pas le chemin tactile, et des évènements de pointeur synthétiques dans un navigateur non plus. Ces défauts ne se reproduisent que sur simulateur, avec `touch_path` et une vraie temporisation — un appui trop court y passe pour un défilement et fait conclure à tort que rien ne marche. Un témoin utile porte sur le mécanisme (identité des éléments DOM, `defaultPrevented`, appel du rappel), jamais sur le rendu final.

## Un graphique déclare ses séries, `BaseChart` fait le reste

`resources/js/Components/Stats/BaseChart.vue` porte l'unique `ChartJS.register(...)` de l'application, l'infobulle, la légende, les axes et la hauteur. Une carte ne garde que ses `labels`, ses `datasets` et ce qui la distingue vraiment. Une garde de `tests/js/conventions/chartChunks.test.js` vérifie qu'aucun autre fichier n'importe `chart.js` ou `vue-chartjs`.

Ce que la carte passe en props plutôt qu'en options recopiées : `type`, `hauteur`, `legende` (absente : cachée sur des barres ou une courbe, visible sous un anneau), `infobulle` (`{ accent, opaque, …réglages Chart.js }`), `axeX`/`axeY` (`false` cache l'axe **sans perdre son échelle**), `axeY1`, `indexAxis`, `interaction`, `lueur` + `lueurOpacite` (à la place d'un `<style scoped>` qui ne portait qu'un `drop-shadow`), `plugins`, `vide` avec les créneaux `#vide` et `#surcouche`. Le créneau `options` fusionne en dernier, branche par branche : il est là pour le cas non prévu, pas pour rebâtir l'habillage.

Trois règles tenues à la main, parce qu'une factorisation « sans changement de comportement » a reproduit des divergences que personne n'avait comparées à l'écran (retour de Sam, 2026-09-05) : **les dates d'un axe s'écrivent `jj/mm`** (`etiquetteDeDate` côté client, `format('d/m')` côté serveur) ; **une carte de page montre ses deux axes**, seuls les encarts du tableau de bord (`RecentWorkouts*`, `RecentPRs`) et les vignettes de la page de statistiques (`compact`) restent nus ; **un anneau a ses parts habillées par `BaseChart` et sa légende sous le canevas**, et une carte qui fixe la hauteur de son graphique reçoit `hauteur="h-full"` plutôt que d'y laisser un vide. Avant de livrer une carte, la regarder à côté de ses voisines.

Deux pièges payés pendant la migration des 48 cartes : `BaseChart` ne pose `beginAtZero` que sur des barres, donc une courbe qui l'attendait doit le redemander par `:axe-y` ; et le composant s'importe en chemin relatif depuis le dossier des cartes, jamais par l'alias, que la garde imposant `defineAsyncComponent` refuse sur tout fichier de graphique.

## Un graphique, un champ ou une case se nomment pour qui ne les voit pas
Depuis #1970, #1971 et #1972 (2026-10-05).

**Un graphique** passe `description` à `BaseChart` : c'est le nom que le lecteur d'écran annonce pour le canevas, que vue-chartjs rend en `role="img"`. `BaseChart` écrit aussi les valeurs tracées dans un paragraphe `sr-only` relié au canevas par `aria-describedby`, une phrase par série nommée par son `label` : « Poids (kg) — 01/10 : 80 ; 02/10 : 80,5. ». Le nom d'une série se lit donc tel quel, en français et avec son unité entre parenthèses. Ne pas déplacer ce résumé dans le contenu de repli du canevas : les enfants d'un `role="img"` sont ignorés des technologies d'assistance, et les composants typés de vue-chartjs (`Bar`, `Line`…) ne transmettent même pas ce créneau. `tests/js/conventions/chartChunks.test.js` exige `description` de chaque appel et refuse un nom de série anglais ; `tests/js/components/stats/nomEtResumeDesGraphiques.test.js` monte chaque carte avec le vrai vue-chartjs et lit le canevas rendu.

**Un champ** ne se nomme jamais par son seul placeholder, qui s'efface dès qu'il est rempli et ne se voit pas sur un champ prérempli. `GlassInput`, `GlassSelect`, `GlassTextarea` reçoivent `label`, avec `hide-label` là où la place manque ; un champ brut répété par rangée reçoit un `aria-label` qui dit sa rangée (« Répétitions, série 1, Squat », comme `RangeeDeSerie`), et le rang de l'exercice quand un modèle le contient deux fois (« Squat (exercice 3) », `nomDeLExercice` de `TemplateForm`) ; un libellé posé à part vise le champ par `for` et `id`. **Une case** se place dans son `<label class="min-h-touch …">` avec son texte : le texte la nomme, la coche, et donne la cible de 44 px que la case seule (20 px) n'atteint pas. Deux cases voisines ont deux noms distincts. `tests/js/conventions/champsNommes.test.js` refuse un champ sans nom ; seuls les composants primitifs qui posent eux-mêmes l'étiquette en sont exemptés. Un moyen n'y compte que s'il atteint le contrôle : `Checkbox` et `GlassTextarea` laissent leurs attributs sur leur div racine, si bien qu'un `aria-label` ou un `id` posé sur eux ne nomme rien, et un attribut `label` sur un `<input>` brut non plus. La garde monte chaque composant pour vérifier sa table.

## Il n'y aura pas de mode sombre : une seule apparence, partout

Décision du propriétaire du 07/09/2026, définitive : #1806 est fermée comme non planifiée. Le mode sombre avait été retiré en #1580 parce qu'il s'écrivait paire par paire — `bg-white` et son jumeau `dark:bg-slate-800` — et que 238 utilitaires clairs sur 449 n'avaient aucun jumeau. Ce n'est plus un report en attendant mieux, c'est un choix qu'on tient.

Interdits, et tenus par les quatre contrôles de `LeModeSombreNeRevientPasTest` : une variante `dark:`, un `@variant dark` ou un bloc `.dark` dans `app.css`, un `prefers-color-scheme: dark`, un sélecteur `[data-theme="dark"]`. Les deux derniers ont été ajoutés le 07/09/2026 : sans eux, un thème sombre complet a vécu des mois dans la page de la charte, sur la page même qui explique pourquoi il n'y en a plus.

Le pendant positif compte autant. `color-scheme: light` est déclaré sur `html` dans `app.css` : sans lui, un téléphone réglé en sombre laisse le navigateur repeindre ce qu'il dessine lui-même, et les cases à cocher, listes déroulantes, ascenseurs et champs de saisie sortent en sombre au milieu de surfaces claires.

**Le piège vaut hors de l'application Vue.** Un paquet qui rend une interface active souvent son mode sombre par défaut, sur `prefers-color-scheme` — exactement le « reçu sans l'avoir demandé » qui a coûté le mode sombre. D'où `->darkMode(false)` sur le panneau Filament et `Theme::Light` dans `config/log-viewer.php`, et le réflexe à garder pour tout nouveau paquet. Le manifeste PWA compte aussi : `theme_color` et `background_color` y sont restés en `#0f172a` longtemps après le retrait, si bien que l'écran de démarrage de l'application installée s'ouvrait en bleu nuit sur une application claire.

Deux choses à ne PAS « corriger » au passage : `--color-text-on-dark-accent` sert les accents FONCÉS — un bouton orange — et non un thème ; et les `color-scheme: light` des gabarits de courriel sont la bonne déclaration, pas un reliquat.

## Le service worker écrit sans jeton CSRF : c'est `Sec-Fetch-Site: same-origin` qui l'autorise
Décision du 2026-10-02 (#1847). `resources/js/sw/renouvellementDAbonnement.js` renvoie au serveur l'abonnement que le navigateur a remplacé (`pushsubscriptionchange`) et fait oublier l'ancien. Un worker n'a pas de document où lire le `<meta name="csrf-token">`, et garder le jeton dans un cache est fragile, puisqu'il tourne. Il poste donc sans jeton : PreventRequestForgery de Laravel 13 accepte toute écriture qui porte `Sec-Fetch-Site: same-origin`, en-tête que seul le navigateur pose. Ce n'est PAS une exemption : n'ajoute jamais ces routes à `except`, et n'active ni `useOriginOnly` ni `allowSameSite` pour les faire passer. `RequestForgeryProtectionTest` tient la voie par HTTP, avec une sous-classe qui coupe le contournement de `runningUnitTests()` — sans elle, aucun test ne voit jamais un 419.

Trois pièges. Le worker n'a pas Ziggy : ses deux adresses sont écrites en dur, et `PushSubscriptionControllerTest` les compare à `route(..., absolute: false)` ; renommer l'une de ces routes doit faire tomber ce test. Il demande du JSON (`Accept`) pour qu'une session expirée réponde 401, là où une redirection serait suivie par `fetch` jusqu'à un 200 trompeur. Et iOS n'émet pas l'évènement, selon MDN : la réparation y passe par `rapprocherLAbonnementPush`, que le layout authentifié appelle une fois par chargement et le profil à son montage. Elle compare l'abonnement du navigateur au mémo `gym-tracker:abonnement-push-transmis`, qui garde le compte ET l'adresse, un seul par appareil : il dit à quel compte l'appareil a été donné. Le serveur réattribue une adresse au dernier compte qui l'enregistre (`updatePushSubscription` supprime la ligne de l'autre) : transmettre pour quiconque se connecte enverrait ses notifications sur l'écran verrouillé du propriétaire, et la déconnexion ne détache l'appareil que si elle passe par `seDeconnecter` (section suivante) — une session qui expire ne détache rien. Le rapprochement ne transmet donc que si le mémo est vide ou au compte connecté ; un appareil donné à un autre compte reste à ce compte, et seule l'activation du profil, un geste fait sur l'appareil, le fait changer de mains. Elle rend `false` dans ce cas comme quand le serveur refuse : le profil remontre alors le bandeau « Activer » au lieu des cases d'envoi en push. Ne pas revenir à « celui qui revient reprend l'adresse » sans l'accord du propriétaire (question ouverte du 2026-10-03).

## Se déconnecter passe par `seDeconnecter`, qui détache d'abord l'appareil
Décision du 2026-10-03 (#1926), prise en l'absence du propriétaire, la plus protectrice. Le serveur ne sait pas quel appareil se déconnecte : sans rien côté page, l'appareil gardait les notifications du compte parti, records et rappels compris, et la personne suivante les voyait. `resources/js/composables/useDeconnexion.js` est donc la seule porte de sortie : le menu du layout (`DropdownLink` sans `href` devient un bouton), le profil et la page de vérification de l'adresse l'appellent. Elle appelle `detacherLAppareil` puis poste `logout`. Un `<Link :href="route('logout')">` ou un `router.post` écrit ailleurs partirait sans détacher : `tests/js/conventions/deconnexion.test.js` refuse que la route soit nommée hors de ce fichier.

`detacherLAppareil` fait, dans cet ordre : oublier l'adresse du navigateur côté serveur, `unsubscribe()`, effacer le mémo. Le compte suivant réactive lui-même depuis son profil. Le désabonnement a lieu même quand le mémo nomme un autre compte : après une déconnexion, l'appareil ne sert plus personne. Le serveur, lui, n'oublie que les lignes du compte connecté (`deletePushSubscription` passe par la relation) ; `OubliDAbonnementPushTest` le tient. Rien de tout cela ne doit retenir la déconnexion :
- `DELAI_DE_DETACHEMENT_MS` (600 ms) borne l'attente ; au-delà la déconnexion part, et le désabonnement s'achève après coup — il suffit seul à faire taire l'appareil ;
- l'oubli encore en vol est ABANDONNÉ avant que la déconnexion parte, et un oubli pas encore parti n'est plus envoyé : sa réponse, arrivée après celle de `logout`, rendrait au navigateur le cookie de la session close ;
- l'abonnement se lit par `getRegistration()`, pas par `ready` : sans worker, `ready` ne se règle jamais et chaque déconnexion paierait tout le délai ;
- le rapprochement en vol est attendu, sinon il réécrit le mémo après l'effacement, et ce que le chargement a transmis est oublié, pour qu'un compte qui se reconnecte sans recharger la page soit de nouveau rapproché.

La suppression du compte détache aussi l'appareil, mais seulement dans `onSuccess` et avec `prevenirLeServeur: false` : avant la réponse, un mot de passe refusé coûterait l'abonnement d'un compte qui reste ; après, la session n'existe plus et l'oubli répondrait 401.

## Un mot de passe changé retire l'abonnement de chaque appareil : la page le retransmet
Suite de #1940 (2026-10-04) : `DetacheSesAppareilsPush` (trait de `User`) retire tous les abonnements push du compte quand son mot de passe change, par n'importe quel chemin (`.ai/rules/models.md`), puisque le serveur ne sait pas quelle ligne appartient à quelle session. Le mémo de l'appareil disait pourtant toujours « déjà transmis » : rien ne réécrivait plus, et le profil d'un appareil reconnecté montrait les cases d'envoi en push pour des envois qui ne lui parvenaient plus. Deux portes le démentent, sans changer le compte qu'il nomme :
- `retransmettreLAbonnementPush`, que `UpdatePasswordForm` appelle dans `onSuccess` seulement : l'appareil qui a changé le mot de passe garde sa session et rend aussitôt le sien. Il attend d'abord les rapprochements en vol : l'un d'eux, parti avant le changement, noterait sinon après coup une transmission que le serveur vient d'effacer ;
- `marquerLAbonnementARetransmettre`, que `GuestLayout` appelle sur toute page d'invité (pas sous une session ouverte : vérification de l'adresse, confirmation du mot de passe). Le mémo prend `aRetransmettre`, et la connexion suivante AU MÊME COMPTE retransmet une fois ; un appareil donné à un autre compte reste à cet autre compte. La marque oublie aussi ce que le chargement a déjà rapproché : la page de connexion et celle qui la suit se succèdent sans recharger le module.

Un mémo marqué ne dispense jamais d'écrire, et un échec garde la marque : l'ouverture suivante réessaie. Le coût est une écriture par connexion d'un appareil abonné. Garder côté serveur la ligne de l'appareil courant, sur une adresse que le formulaire enverrait, ne suffirait pas : les appareils retirés qui se reconnectent au compte resteraient muets sans la marque.

## `route()` vient du bundle, pas de la page
Décision du 2026-10-05 (#1969). `config/ziggy.php` pose `skip-route-function` : `@routes` n'écrit plus dans la page que la table (`const Ziggy`), et la fonction, 21 Ko de script en ligne dans chaque page complète, vient du bundle. `main.js` la pose en globale par `installerLaRouteGlobale()` (`resources/js/Utils/routeGlobale.js`) avant `createInertiaApp`, et les `<script setup>` l'appellent toujours sans l'importer. Le piège : un module que `main.js` importe statiquement est évalué AVANT ce corps ; un `route()` écrit au niveau supérieur d'un tel module lèverait « route is not defined ». Appeler `route()` dans une fonction ou un `setup`, jamais à l'import. `PoidsDesPagesCompletesTest` borne `/login` et `/dashboard` à 4 Kio compressés et refuse le retour de la fonction dans la page ; `tests/js/utils/routeGlobale.test.js` tient la pose.

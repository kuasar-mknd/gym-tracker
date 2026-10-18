import { URL_DE_LA_PAGE_HORS_LIGNE } from './navigationsHorsLigne.js'
import { DISPOSITIONS_DE_LA_MARQUE, TUILE_DU_LOGO, logoEnSvg } from '../Utils/dessinDuLogo.js'

/**
 * La page « hors ligne », écrite à la construction (#1966), devenue la coquille
 * de l'application (#2048, partie F).
 *
 * Le worker la sert à une navigation que le réseau ne sert pas. Elle ne porte
 * rien d'un compte : un document fixe, le même pour tous, construit avec les
 * actifs et précaché avec eux, sans page d'Inertia ni jeton. Elle prend la
 * feuille de style de l'application, que le precache garde aussi, et donc la
 * charte : ses couleurs viennent des utilitaires de `app.css`, jamais d'une
 * valeur écrite ici.
 *
 * **La coquille.** Elle porte `data-coquille` sur `<html>` et charge le module
 * de l'entrée construite (`<script type="module" src="/build/assets/main-…">`,
 * le vrai nom haché, au precache avec sa fermeture). Ce module voit la marque
 * et démarre l'application sur la copie chiffrée de l'adresse, quand le
 * magasin en a une lisible pour le compte (`Utils/demarrageHorsLigne.js`) :
 * c'est lui qui écrit la page d'Inertia dans le document, jamais ce fichier.
 * Sinon, son contenu reste tel quel : « Pas de réseau », ce qu'il faut faire
 * avant la salle, et « Réessayer ». Aucun script en ligne : la politique que le
 * worker pose sur la page (`script-src 'self'`, `coquilleServie.js`) le
 * bloquerait. Le rechargement au retour du réseau, que portait le script en
 * ligne, passe donc dans le module, comme « Attendre la réponse », caché tant
 * que le module ne le montre pas (raison `delai`, aucune copie).
 *
 * Rien à charger d'autre que cette feuille, ses polices et le module : le logo
 * est tracé dans la page, comme dans l'en-tête de l'application, et
 * « Réessayer » redemande la page qui n'a pas pu s'ouvrir. L'icône `cloud_off`
 * vient de la police Material Symbols, au precache depuis #2048 : un démarrage
 * à froid sans réseau l'affiche.
 */

/** Le nom du fichier dans public/build, d'où le precache le prend. */
export const FICHIER_DE_LA_PAGE_HORS_LIGNE = URL_DE_LA_PAGE_HORS_LIGNE.replace(/^\/build\//, '')

/** L'attribut de `<html>` qui dit au module de l'entrée qu'il démarre dans la coquille. */
export const ATTRIBUT_DE_LA_COQUILLE = 'data-coquille'

/** Le bouton « Attendre la réponse », caché tant que le module ne le montre pas. */
export const ATTRIBUT_D_ATTENTE = 'data-attendre-la-reponse'

/** Le paragraphe qui dit pourquoi la page n'a pas pu s'ouvrir, que le module adapte à la raison. */
export const ATTRIBUT_DU_MOTIF = 'data-motif'

/** Ce que dit la coquille quand le réseau n'a pas répondu à temps (raison `delai`). */
export const MOTIF_DU_DELAI = "Cette page n'a pas pu s'ouvrir : le réseau ne répond pas."

/** La phrase qui dit, sans copie, ce qu'il faut faire avant la salle. */
export const AVANT_LA_SALLE =
    "Ouvre l'application une fois avec du réseau avant la salle : l'accueil, la séance en cours, les séances, les exercices et les outils resteront disponibles pendant la durée de ta session."

/** La marque des pages invitées : le logo seul sur sa tuile, le nom lu mais caché. */
const MARQUE = DISPOSITIONS_DE_LA_MARQUE.page

const echapper = (texte) => texte.replace(/[&<>"']/g, (caractere) => `&#${caractere.charCodeAt(0)};`)

/**
 * Le document, avec les feuilles de style et le module de l'entrée de
 * l'application.
 *
 * @param {{ feuilles: string[], moduleDeLEntree: string }} options Les URL
 *   des feuilles et du module de l'entrée, absolues.
 * @returns {string}
 */
export const pageHorsLigne = ({ feuilles, moduleDeLEntree }) => `<!DOCTYPE html>
<html lang="fr" ${ATTRIBUT_DE_LA_COQUILLE}>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>Hors ligne - GymTracker</title>
${feuilles.map((feuille) => `    <link rel="stylesheet" href="${echapper(feuille)}">`).join('\n')}
    <script type="module" src="${echapper(moduleDeLEntree)}"></script>
</head>
<body class="bg-surface-page text-text-main font-sans antialiased">
    <main id="main-content" class="px-sure-6 py-sure-12 flex min-h-dvh flex-col items-center justify-center gap-8">
        <div class="flex items-center ${MARQUE.sens}">
            ${logoEnSvg(`${TUILE_DU_LOGO} ${MARQUE.logo}`)}
            <p class="text-gradient font-display ${MARQUE.nom} font-black tracking-tight uppercase italic">GymTracker</p>
        </div>
        <section class="glass-panel-light w-full max-w-md space-y-4 rounded-3xl p-8 text-center" dusk="page-hors-ligne">
            <span class="material-symbols-outlined text-text-muted text-5xl" aria-hidden="true" dusk="icone-hors-ligne">cloud_off</span>
            <h1 class="titre-carte text-2xl">Pas de réseau</h1>
            <p class="text-text-muted text-base font-medium" ${ATTRIBUT_DU_MOTIF}>Cette page n'a pas pu s'ouvrir : l'appareil n'est pas connecté.</p>
            <p class="text-text-muted text-sm font-medium">${AVANT_LA_SALLE}</p>
            <p class="text-text-muted text-sm font-medium">
                Ce que tu as saisi pendant une séance reste sur l'appareil, et partira au retour du réseau.
            </p>
            <div class="flex flex-wrap items-center justify-center gap-3">
                <a href="" class="glass-button glass-button-primary min-h-touch rounded-xl px-5 py-2.5 text-base">Réessayer</a>
                <button type="button" hidden ${ATTRIBUT_D_ATTENTE} class="glass-button border-border-strong hover:bg-surface-sunken min-h-touch rounded-xl bg-transparent px-5 py-2.5 text-base shadow-none backdrop-blur-none">Attendre la réponse</button>
            </div>
        </section>
    </main>
</body>
</html>
`

/**
 * Le greffon de Vite qui écrit la page dans public/build, à côté des actifs.
 *
 * Il la tire du morceau de l'entrée que la construction a réellement produit,
 * et de ses feuilles de style : leur nom porte un hachage qui change à chaque
 * construction, et le precache, qui les range, doit servir exactement
 * ceux-là. Une seule entrée : la coquille ne charge qu'un module.
 */
export const greffonDeLaPageHorsLigne = () => ({
    name: 'gym-tracker:page-hors-ligne',
    apply: 'build',
    generateBundle(_options, paquet) {
        const entrees = Object.values(paquet).filter((morceau) => morceau.type === 'chunk' && morceau.isEntry)

        if (entrees.length !== 1) {
            this.error(`La page « hors ligne » attend une seule entrée construite, et en a trouvé ${entrees.length}.`)
        }

        const [entree] = entrees
        const feuilles = [...(entree.viteMetadata?.importedCss ?? [])].map((feuille) => `/build/${feuille}`)

        if (feuilles.length === 0) {
            this.error("La page « hors ligne » n'a trouvé aucune feuille de style dans l'entrée construite.")
        }

        this.emitFile({
            type: 'asset',
            fileName: FICHIER_DE_LA_PAGE_HORS_LIGNE,
            source: pageHorsLigne({ feuilles, moduleDeLEntree: `/build/${entree.fileName}` }),
        })
    },
})

import { URL_DE_LA_PAGE_HORS_LIGNE } from './navigationsHorsLigne.js'

/**
 * La page « hors ligne », écrite à la construction (#1966).
 *
 * Le worker la sert à une navigation que le réseau ne sert pas. Elle ne porte
 * rien d'un compte : un document fixe, le même pour tous, construit avec les
 * actifs et précaché avec eux. Elle prend la feuille de style de l'application,
 * que le precache garde aussi, et donc la charte : ses couleurs viennent des
 * utilitaires de `app.css`, jamais d'une valeur écrite ici.
 *
 * Rien à charger d'autre que cette feuille et ses polices : « Réessayer »
 * redemande la page qui n'a pas pu s'ouvrir, et le retour du réseau le fait
 * tout seul.
 */

/** Le nom du fichier dans public/build, d'où le precache le prend. */
export const FICHIER_DE_LA_PAGE_HORS_LIGNE = URL_DE_LA_PAGE_HORS_LIGNE.replace(/^\/build\//, '')

const echapper = (texte) => texte.replace(/[&<>"']/g, (caractere) => `&#${caractere.charCodeAt(0)};`)

/**
 * Le document, avec les feuilles de style de l'entrée de l'application.
 *
 * @param {{ feuilles: string[] }} options Les URL des feuilles, absolues.
 * @returns {string}
 */
export const pageHorsLigne = ({ feuilles }) => `<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>Hors ligne - GymTracker</title>
${feuilles.map((feuille) => `    <link rel="stylesheet" href="${echapper(feuille)}">`).join('\n')}
</head>
<body class="bg-surface-page text-text-main font-sans antialiased">
    <main id="main-content" class="flex min-h-dvh flex-col items-center justify-center gap-8 px-6 py-12">
        <p class="text-gradient font-display text-4xl font-black tracking-tight uppercase italic">GymTracker</p>
        <section class="glass-panel-light w-full max-w-md space-y-4 rounded-3xl p-8 text-center" dusk="page-hors-ligne">
            <h1 class="titre-carte text-2xl">Pas de réseau</h1>
            <p class="text-text-muted text-base font-medium">
                Cette page n'a pas pu s'ouvrir : l'appareil n'est pas connecté.
            </p>
            <p class="text-text-muted text-sm font-medium">
                Ce que tu as saisi pendant une séance reste sur l'appareil, et partira au retour du réseau.
            </p>
            <a href="" class="glass-button glass-button-primary min-h-touch rounded-xl px-5 py-2.5 text-base">Réessayer</a>
        </section>
    </main>
    <script>
        window.addEventListener('online', () => window.location.reload())
    </script>
</body>
</html>
`

/**
 * Le greffon de Vite qui écrit la page dans public/build, à côté des actifs.
 *
 * Il la tire des feuilles de style que la construction a réellement produites
 * pour l'entrée : leur nom porte un hachage qui change à chaque construction,
 * et le precache, qui les range, doit servir exactement celles-là.
 */
export const greffonDeLaPageHorsLigne = () => ({
    name: 'gym-tracker:page-hors-ligne',
    apply: 'build',
    generateBundle(_options, paquet) {
        const feuilles = Object.values(paquet)
            .filter((morceau) => morceau.type === 'chunk' && morceau.isEntry)
            .flatMap((morceau) => [...(morceau.viteMetadata?.importedCss ?? [])])
            .map((feuille) => `/build/${feuille}`)

        if (feuilles.length === 0) {
            this.error("La page « hors ligne » n'a trouvé aucune feuille de style dans l'entrée construite.")
        }

        this.emitFile({ type: 'asset', fileName: FICHIER_DE_LA_PAGE_HORS_LIGNE, source: pageHorsLigne({ feuilles }) })
    },
})

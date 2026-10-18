import { PAGES_GARDEES } from '../Utils/pagesGardees.js'

/**
 * La fermeture du precache : tout ce dont l'entrée et les pages de la salle
 * ont besoin pour s'ouvrir sans réseau (#2048, partie E, décision 9 du
 * propriétaire du 2026-10-12).
 *
 * Une liste de motifs choisissait le precache (`assets/main-*`, `vue-*`,
 * `Dashboard-*`, `Show-*`), et elle n'en couvrait qu'une partie : la séance
 * demande 23 fichiers JS et 3 feuilles, dont la sienne (`Show-*.css`), qu'aucun
 * motif ne prenait ; `Show-*.js` attrapait par hasard les morceaux d'autres
 * pages, et le glisser-déposer de la séance n'y entrait que parce que son
 * morceau s'appelle `vue-*.js`. Une copie servie sans réseau (partie E) monte
 * le morceau de sa page puis tout ce qu'il importe : un seul fichier absent, et
 * la page ne s'ouvre pas.
 *
 * Le precache prend donc, depuis le manifeste de la construction
 * (`manifest.json`), la fermeture de chaque point de départ : le fichier de
 * l'entrée ou de la page, ses feuilles (`css`), ce qu'il importe (`imports`) et
 * ce qu'il charge à la demande (`dynamicImports`), puis de même pour chacun.
 * Deux arrêts, et seulement deux :
 * - une page qui n'est pas un point de départ : l'entrée charge chaque page à
 *   la demande (`import.meta.glob`), et la suivre mettrait toute l'application
 *   au precache ;
 * - un graphique de `GRAPHIQUES_INDISPONIBLES_HORS_LIGNE`, qui dit sans réseau
 *   « Graphique indisponible hors ligne » (`graphiqueAsynchrone`), avec
 *   Chart.js, que seuls les graphiques importent.
 * Les polices restent aux motifs de `vite.config.js`, qui choisissent leurs
 * sous-ensembles (les latines et celle des icônes, jamais `latin-ext`).
 *
 * `vite.config.js` lit ce module sous Node : des imports relatifs, ni Vue ni
 * Inertia. `ServiceWorkerAssetsTest` refait la fermeture depuis le manifeste
 * construit et la cherche au precache ; `Tests\Support\FermetureDuPrecache` y
 * lit les deux listes, une entrée par ligne, et refuse toute autre forme.
 */

/** L'entrée de l'application, sous son nom dans le manifeste. */
export const ENTREE_DE_L_APPLICATION = 'resources/js/main.js'

/** Le dossier des pages, d'où l'entrée les charge à la demande. */
export const DOSSIER_DES_PAGES = 'resources/js/Pages/'

/** Un composant de graphique, sous son nom dans le manifeste. */
export const MOTIF_D_UN_GRAPHIQUE = /^resources\/js\/Components\/Stats\/\w+Chart\.vue$/

/** Une police : les motifs de `vite.config.js` la prennent, ou non. */
export const MOTIF_D_UNE_POLICE = /\.woff2$/

/** L'adresse d'où le serveur sert la construction, que le precache donne à chaque fichier. */
export const ADRESSE_DE_LA_CONSTRUCTION = '/build/'

/**
 * Les graphiques que les pages gardées chargent à la demande, et qui restent
 * hors du precache (décision 9 : « indisponibles hors ligne »). La liste est
 * exacte : la construction s'arrête sur un graphique qu'une page gardée charge
 * sans qu'il soit nommé ici (il mettrait Chart.js au precache), comme sur un
 * graphique nommé qu'aucune ne charge plus.
 *
 * Une entrée par ligne, sous la forme `'resources/js/…Chart.vue',`.
 */
export const GRAPHIQUES_INDISPONIBLES_HORS_LIGNE = Object.freeze([
    'resources/js/Components/Stats/ActiveGoalsChart.vue',
    'resources/js/Components/Stats/DashboardDurationChart.vue',
    'resources/js/Components/Stats/DurationDistributionChart.vue',
    'resources/js/Components/Stats/ExerciseCategoryChart.vue',
    'resources/js/Components/Stats/MonthlyVolumeChart.vue',
    'resources/js/Components/Stats/OneRepMaxPercentagesChart.vue',
    'resources/js/Components/Stats/RecentPRsChart.vue',
    'resources/js/Components/Stats/RecentWorkoutsChart.vue',
    'resources/js/Components/Stats/RecentWorkoutsExercisesChart.vue',
    'resources/js/Components/Stats/RecentWorkoutsTimelineChart.vue',
    'resources/js/Components/Stats/TimeOfDayChart.vue',
    'resources/js/Components/Stats/VolumePerWorkoutChart.vue',
    'resources/js/Components/Stats/WeeklyVolumeChart.vue',
    'resources/js/Components/Stats/WorkoutDurationChart.vue',
    'resources/js/Components/Stats/WorkoutFrequencyChart.vue',
    'resources/js/Components/Stats/WorkoutHistoryTimelineChart.vue',
    'resources/js/Components/Stats/WorkoutsPerMonthChart.vue',
])

/**
 * Les pages qui s'ouvrent sans réseau sans être gardées, par leur composant :
 * aucune encore. La page `HorsLigne` de la partie F (#2048), que la coquille
 * montre à une adresse sans copie, s'y ajoute. `demarrageHorsLigne`, que
 * `main.js` importera statiquement, est déjà dans la fermeture de l'entrée.
 *
 * Une entrée par ligne, sous la forme `'Composant',`.
 */
export const PAGES_PRECACHEES_SANS_COPIE = Object.freeze([])

/**
 * Les points de départ, sous leur nom dans le manifeste : l'entrée, chaque
 * page gardée et chaque page précachée sans copie, une fois chacune.
 *
 * @param {{ pagesGardees?: readonly { composant: string }[], pagesSansCopie?: readonly string[] }} [listes]
 * @returns {string[]}
 */
export const pointsDeDepartDuPrecache = ({
    pagesGardees = PAGES_GARDEES,
    pagesSansCopie = PAGES_PRECACHEES_SANS_COPIE,
} = {}) => [
    ENTREE_DE_L_APPLICATION,
    ...new Set(
        [...pagesGardees.map((page) => page.composant), ...pagesSansCopie].map(
            (composant) => `${DOSSIER_DES_PAGES}${composant}.vue`,
        ),
    ),
]

/**
 * @typedef {{
 *   file: string,
 *   css?: string[],
 *   assets?: string[],
 *   imports?: string[],
 *   dynamicImports?: string[],
 * }} EntreeDuManifeste
 *   Une entrée de `manifest.json`, sous le nom de son module source.
 */

/**
 * La fermeture du precache, depuis le manifeste de la construction.
 *
 * @param {Record<string, EntreeDuManifeste>} manifeste
 * @param {{ depart?: string[], graphiques?: readonly string[] }} [options]
 * @returns {{ fichiers: string[], graphiquesEcartes: string[] }} les fichiers,
 *   sous leur chemin dans public/build, triés, et les graphiques écartés.
 * @throws {Error} sur un point de départ ou un import absent du manifeste, et
 *   sur un graphique non nommé, importé statiquement ou nommé sans être chargé.
 */
export const fermetureDuPrecache = (
    manifeste,
    { depart = pointsDeDepartDuPrecache(), graphiques = GRAPHIQUES_INDISPONIBLES_HORS_LIGNE } = {},
) => {
    const entree = (nom, depuis) => {
        if (!Object.hasOwn(manifeste, nom)) {
            throw new Error(
                depuis === null
                    ? `Le point de départ du precache ${nom} est absent du manifeste de la construction.`
                    : `${depuis} importe ${nom}, absent du manifeste de la construction.`,
            )
        }

        return manifeste[nom]
    }

    const departs = new Set(depart)
    const nommes = new Set(graphiques)
    const graphiquesEcartes = new Set()
    const fichiers = new Set()
    const vus = new Set()
    const aSuivre = depart.map((nom) => ({ nom, depuis: null }))

    while (aSuivre.length > 0) {
        const { nom, depuis } = aSuivre.pop()

        if (vus.has(nom)) {
            continue
        }

        const { file, css = [], assets = [], imports = [], dynamicImports = [] } = entree(nom, depuis)

        vus.add(nom)
        fichiers.add(file)
        css.forEach((feuille) => fichiers.add(feuille))
        assets.filter((actif) => !MOTIF_D_UNE_POLICE.test(actif)).forEach((actif) => fichiers.add(actif))

        for (const importe of imports) {
            if (MOTIF_D_UN_GRAPHIQUE.test(importe)) {
                throw new Error(
                    `${nom} importe le graphique ${importe} sans graphiqueAsynchrone : Chart.js entrerait au precache.`,
                )
            }

            aSuivre.push({ nom: importe, depuis: nom })
        }

        for (const charge of dynamicImports) {
            if (charge.startsWith(DOSSIER_DES_PAGES) && !departs.has(charge)) {
                continue
            }

            if (MOTIF_D_UN_GRAPHIQUE.test(charge)) {
                if (!nommes.has(charge)) {
                    throw new Error(
                        `${nom} charge le graphique ${charge}, absent de GRAPHIQUES_INDISPONIBLES_HORS_LIGNE (resources/js/sw/fermetureDuPrecache.js) : le nommer, ou il mettrait Chart.js au precache.`,
                    )
                }

                graphiquesEcartes.add(charge)

                continue
            }

            aSuivre.push({ nom: charge, depuis: nom })
        }
    }

    const perimes = [...nommes].filter((graphique) => !graphiquesEcartes.has(graphique))

    if (perimes.length > 0) {
        throw new Error(
            `GRAPHIQUES_INDISPONIBLES_HORS_LIGNE nomme des graphiques qu'aucune page précachée ne charge plus : ${perimes.join(', ')}.`,
        )
    }

    return { fichiers: [...fichiers].sort(), graphiquesEcartes: [...graphiquesEcartes].sort() }
}

/**
 * Le greffon de Vite qui verse la fermeture au precache, jumeau de
 * `greffonDeLaPageHorsLigne`.
 *
 * Les motifs du precache se lisent avant la construction, la fermeture se
 * calcule après elle : le greffon lit donc le manifeste que la construction
 * vient d'écrire (`writeBundle`) et ajoute ses fichiers aux entrées
 * supplémentaires de vite-plugin-pwa (`api.extendManifestEntries`), que le
 * worker reçoit ensuite (`closeBundle`). Chaque fichier porte son empreinte
 * dans son nom : sans révision, comme Workbox l'admet. Une entrée
 * supplémentaire déjà là (les icônes de public/) n'est pas doublée ; les
 * motifs, que Workbox lit après, ne prennent que la page « hors ligne » et les
 * polices, que la fermeture laisse. Une adresse en double, que Workbox refuse
 * à l'installation du worker, fait échouer `scripts/verifier-le-precache.mjs`
 * et `ServiceWorkerAssetsTest`.
 */
export const greffonDeLaFermetureDuPrecache = () => {
    /** @type {{ extendManifestEntries: (rappel: (entrees: object[]) => object[]) => void } | undefined} */
    let pwa
    /** @type {string | undefined} */
    let fichierDuManifeste

    return {
        name: 'gym-tracker:fermeture-du-precache',
        apply: 'build',
        configResolved(config) {
            pwa = config.plugins.find((greffon) => greffon.name === 'vite-plugin-pwa')?.api
            fichierDuManifeste =
                config.build.manifest === true ? '.vite/manifest.json' : config.build.manifest || undefined
        },
        writeBundle(_options, paquet) {
            if (pwa === undefined) {
                this.error("La fermeture du precache n'a pas trouvé vite-plugin-pwa dans la construction.")
            }

            const source = fichierDuManifeste === undefined ? undefined : paquet[fichierDuManifeste]?.source

            if (source === undefined) {
                this.error("La fermeture du precache n'a pas trouvé le manifeste de la construction.")
            }

            let fermeture

            try {
                fermeture = fermetureDuPrecache(
                    JSON.parse(typeof source === 'string' ? source : new TextDecoder().decode(source)),
                )
            } catch (erreur) {
                this.error(erreur instanceof Error ? erreur.message : String(erreur))
            }

            pwa.extendManifestEntries((entrees) => {
                const deja = new Set(entrees.map((entree) => entree.url))

                return [
                    ...entrees,
                    ...fermeture.fichiers
                        .map((fichier) => ({ url: `${ADRESSE_DE_LA_CONSTRUCTION}${fichier}`, revision: null }))
                        .filter((entree) => !deja.has(entree.url)),
                ]
            })
        },
    }
}

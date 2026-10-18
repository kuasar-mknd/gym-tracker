import { computed, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { MARQUE_D_UNE_COPIE, estUneCopie, propAbsente } from '@/Utils/copiesServies'

/**
 * Ce qu'une page sait de la copie hors ligne qu'elle montre (#2048, partie E).
 *
 * Une page servie depuis une copie porte la marque `props.horsLigne`
 * (`Utils/copiesServies.js`) : rien ne part d'elle vers le serveur, sauf la
 * déconnexion (`Utils/visitesHorsLigne.js` arrête toute visite qui n'est pas un
 * GET), et ce qu'aucune copie ne garde (le dernier poids, les objectifs de
 * l'accueil) y manque. Une page s'en sert pour le montrer d'avance :
 *
 * - `surUneCopie` désactive un bouton qui écrirait par une visite d'Inertia,
 *   et `raisonDUneEcriture` lui donne sa raison pour un lecteur d'écran
 *   (`aria-describedby`). Le texte vit dans le bandeau de la copie
 *   (`BandeauHorsLigne.vue`), que le layout authentifié montre sur toute
 *   page servie ainsi ;
 * - `indisponibleSurLaCopie(nom)` dit qu'une prop manque parce que la page est
 *   une copie, et non parce que le compte n'a rien à montrer.
 *
 * Les écritures de la séance passent par la file hors ligne, qui les garde sans
 * réseau : elles restent ouvertes sur une copie.
 *
 * Une copie qui se recharge au retour du réseau n'est pas remontée :
 * `router.reload` garde l'état de la page, et un minuteur lancé sur la copie
 * continue. Un formulaire qu'une page gardée remplit depuis ses props se
 * recale donc lui-même quand la marque part (`recalerApresLaCopie`).
 */

/** L'identifiant du texte qui dit pourquoi un bouton d'écriture est désactivé sur une copie. */
export const ID_DE_LA_RAISON_DES_ECRITURES = 'ecritures-sur-une-copie'

/** Ce que le lecteur d'écran lit d'un bouton d'écriture désactivé sur une copie. */
export const RAISON_DES_ECRITURES_SUR_UNE_COPIE = 'Cette page est une copie hors ligne : ce geste attend le réseau.'

/**
 * @returns {{
 *   marque: import('vue').ComputedRef<{ copieDu: number, raison: string, datesDesProps?: Record<string, number> } | null>,
 *   surUneCopie: import('vue').ComputedRef<boolean>,
 *   raisonDUneEcriture: import('vue').ComputedRef<string | undefined>,
 *   indisponibleSurLaCopie: (nom: string) => boolean,
 * }}
 */
export const useCopieHorsLigne = () => {
    const page = usePage()

    const surUneCopie = computed(() => estUneCopie(page))
    const marque = computed(() => (surUneCopie.value ? page.props[MARQUE_D_UNE_COPIE] : null))
    const raisonDUneEcriture = computed(() => (surUneCopie.value ? ID_DE_LA_RAISON_DES_ECRITURES : undefined))

    /**
     * Si la prop `nom` manque à la page parce qu'elle est une copie.
     *
     * @param {string} nom
     * @returns {boolean}
     */
    const indisponibleSurLaCopie = (nom) => surUneCopie.value && propAbsente(page.props ?? {}, nom)

    return { marque, surUneCopie, raisonDUneEcriture, indisponibleSurLaCopie }
}

/**
 * L'empreinte de chaque champ : deux valeurs égales, tableaux et objets
 * compris, s'y écrivent pareil.
 *
 * @param {Record<string, unknown>} valeurs
 * @returns {Record<string, string>}
 */
const empreintesDesChamps = (valeurs) =>
    Object.fromEntries(Object.entries(valeurs).map(([champ, valeur]) => [champ, JSON.stringify(valeur)]))

/**
 * Recale, quand la page cesse d'être une copie hors ligne, un formulaire
 * qu'une page gardée remplit depuis ses props (#2048, partie E).
 *
 * Au retour du réseau, une copie se recharge en entier par `router.reload`,
 * qui garde l'état de la page (`preserveState`, qu'Inertia impose) : les
 * props deviennent celles du serveur et la marque part, mais un formulaire
 * rempli au montage gardait les valeurs de la copie, et les renvoyait au
 * serveur au geste suivant, celui que la garde des écritures demande de
 * refaire. Quand la marque part, chaque champ que la personne n'a pas changé
 * depuis son remplissage reprend la valeur que la page tire maintenant de ses
 * props ; un champ qu'elle a changé reste tel qu'elle le voit, et part ainsi.
 * Les valeurs par défaut du formulaire deviennent celles du serveur.
 *
 * Appelée juste après `useForm(valeursDesProps())`, pendant le `setup` du
 * composant, par toute page gardée qui remplit un formulaire depuis ses
 * props (`tests/js/conventions/formulairesDesCopies.test.js`).
 *
 * @param {{ defaults: (valeurs: Record<string, unknown>) => unknown, reset: (...champs: string[]) => unknown }} formulaire
 *   le formulaire d'Inertia (`useForm`)
 * @param {() => Record<string, unknown>} valeursDesProps ce que la page tire
 *   de ses props, relu à chaque recalage
 */
export const recalerApresLaCopie = (formulaire, valeursDesProps) => {
    const { surUneCopie } = useCopieHorsLigne()
    let rempliAvec = empreintesDesChamps(valeursDesProps())

    // Après le rendu : les props, et ce que la page en déduit, sont celles de la réponse.
    watch(
        surUneCopie,
        (copie, etaitUneCopie) => {
            if (copie || !etaitUneCopie) {
                return
            }

            const fraiches = valeursDesProps()
            const inchanges = Object.keys(fraiches).filter(
                (champ) => JSON.stringify(formulaire[champ]) === rempliAvec[champ],
            )

            formulaire.defaults(fraiches)

            if (inchanges.length > 0) {
                formulaire.reset(...inchanges)
            }

            rempliAvec = empreintesDesChamps(fraiches)
        },
        { flush: 'post' },
    )
}

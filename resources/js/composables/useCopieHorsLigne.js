import { computed } from 'vue'
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

import { getCurrentInstance, onMounted, onUnmounted, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import SyncService from '@/Utils/SyncService'
import { identifiantEnFile, seanceConnueDuServeur, superposerLaFile } from '@/Utils/seanceEtFile'
import {
    CLEF_DU_TITULAIRE_DE_L_APPAREIL,
    CLEF_DU_TITULAIRE_DE_L_ONGLET,
    titulaireDeLaPage,
} from '@/Utils/historiqueDuCompte'
import { effacerLesBrouillonsOrphelins } from '@/composables/useBrouillonsDeSeries'

/**
 * La clé sous laquelle l'entrée d'historique de la page garde la séance
 * (`router.remember`), dans l'état mémorisé qu'Inertia chiffre avec la page.
 */
export const SOUVENIR_DE_LA_SEANCE = 'seance'

/** Le chemin d'une adresse, sans requête ni ancre : ce qui distingue deux pages. */
const cheminDe = (adresse) => {
    try {
        return new URL(String(adresse ?? ''), window.location.href).pathname
    } catch {
        return null
    }
}

/**
 * Le titulaire qu'un stockage retient (`historiqueDuCompte`), ou `null` quand il
 * n'en retient aucun ou se refuse.
 *
 * @param {'sessionStorage' | 'localStorage'} nom
 * @param {string} clef
 * @returns {string|null}
 */
const titulaireRetenu = (nom, clef) => {
    try {
        return window[nom]?.getItem(clef) ?? null
    } catch {
        return null
    }
}

/**
 * Un autre titulaire a pris l'onglet ou l'appareil depuis l'ouverture de la
 * séance. `historiqueDuCompte` a alors jeté la clé de l'onglet : les pages du
 * compte parti ne se relisent plus. Gardée maintenant, la séance serait
 * chiffrée avec une clé neuve, que la garde jette à son tour avant toute
 * relecture (Retour, visite, chargement) ; la page n'a de toute façon plus
 * rien à écrire pour un compte parti.
 *
 * @param {string} titulaire celui de la page de séance
 */
const unAutreTitulaire = (titulaire) =>
    [
        titulaireRetenu('sessionStorage', CLEF_DU_TITULAIRE_DE_L_ONGLET),
        titulaireRetenu('localStorage', CLEF_DU_TITULAIRE_DE_L_APPAREIL),
    ].some((retenu) => retenu !== null && retenu !== titulaire)

/**
 * La séance que l'entrée d'historique de la page a gardée, si c'est bien
 * celle des props.
 *
 * @param {object|null} page la page d'Inertia (`usePage()`)
 * @param {object} seance la séance des props
 * @returns {object|null}
 */
const seanceGardee = (page, seance) => {
    const gardee = page?.rememberedState?.[SOUVENIR_DE_LA_SEANCE]

    return gardee !== null &&
        typeof gardee === 'object' &&
        Array.isArray(gardee.workout_lines) &&
        String(gardee.id) === String(seance?.id)
        ? gardee
        : null
}

/**
 * Tient l'entrée d'historique de la page à jour de la séance à l'écran.
 *
 * Inertia range dans l'historique la page de la visite, et les boutons Retour
 * et Avance la rendent telle quelle, sans le serveur. Or la séance s'écrit par
 * l'API, sans visite : tout ce que la page a fait accepter depuis (un exercice,
 * une série, une valeur) en manquait. Une série mise en file sous un exercice
 * ajouté en ligne ne trouvait plus sa ligne et disparaissait avec lui, et une
 * série enregistrée en ligne disparaissait aussi (#2048). Chaque changement de
 * la copie locale est donc gardé dans l'état mémorisé de l'entrée, moins les
 * rangées provisoires que la file rend, et chaque valeur dont l'écriture
 * attend encore sa réponse à sa valeur de repli (`seanceConnueDuServeur`) : une
 * confirmation, qui ne change rien à l'écran, réécrit donc aussi l'entrée.
 *
 * Deux pièges. `router.remember` écrit dans l'entrée COURANTE, et Inertia la
 * change avant que la page parte : une visite qui mène ailleurs pousse la page
 * suivante avant de démonter celle-ci, et le bouton Retour change d'entrée
 * avant même qu'Inertia le sache. Écrit à ce moment, le souvenir de la séance
 * remplaçait la page suivante dans l'historique, adresse comprise. Plus rien ne
 * s'écrit donc dès `beforeUpdate` vers une autre page, qu'Inertia émet avant de
 * la pousser, ni dès un `popstate` vers une autre entrée. Une visite côté
 * client (`router.push`, `router.replace`) n'émet pas `beforeUpdate` : en
 * ajouter une qui quitte cette page demande de figer ce souvenir avant. Et la
 * clé de l'historique part avec le compte (`historiqueDuCompte`, #1965), sur
 * ce même `beforeUpdate` quand la page qui arrive est celle d'un autre compte :
 * rien ne s'écrit donc au départ, pas même une dernière fois, ni quand un
 * autre titulaire a pris l'onglet ou l'appareil. Sans page d'Inertia (un test
 * qui la simule), rien n'est gardé.
 *
 * @param {{
 *   page: object,
 *   localWorkout: import('vue').Ref<object>,
 *   valeursDeRepli: () => Array<{id: unknown, champ: string, valeur: unknown}>,
 * }} seance
 */
const garderDansLHistorique = ({ page, localWorkout, valeursDeRepli }) => {
    const composant = page.component
    const adresse = cheminDe(page.url)
    const titulaire = titulaireDeLaPage(page)
    const empreinteAGarder = () => JSON.stringify(seanceConnueDuServeur(localWorkout.value, valeursDeRepli()))
    let figee = typeof composant !== 'string' || adresse === null || titulaire === null
    let derniere = empreinteAGarder()

    const garder = (empreinte) => {
        figee ||= unAutreTitulaire(titulaire)

        if (figee || page.component !== composant || cheminDe(page.url) !== adresse) {
            return
        }

        if (empreinte !== derniere) {
            derniere = empreinte
            router.remember(JSON.parse(empreinte), SOUVENIR_DE_LA_SEANCE)
        }
    }

    const avantLaPageSuivante = (event) => {
        const suivante = event.detail?.page

        if (suivante?.component !== composant || cheminDe(suivante?.url) !== adresse) {
            figee = true
        }
    }

    const auRetourOuALAvance = (event) => {
        if (event.state?.page !== undefined) {
            figee = true
        }
    }

    // La séance comme les valeurs de repli : l'empreinte lit l'une et les autres.
    watch(empreinteAGarder, garder)

    onMounted(() => {
        document.addEventListener('inertia:beforeUpdate', avantLaPageSuivante)
        window.addEventListener('popstate', auRetourOuALAvance)
    })

    onUnmounted(() => {
        figee = true
        document.removeEventListener('inertia:beforeUpdate', avantLaPageSuivante)
        window.removeEventListener('popstate', auRetourOuALAvance)
    })
}

/**
 * La copie locale de la séance, à l'ouverture de la page : celle que le serveur
 * a rendue, ou celle que l'entrée d'historique a gardée, et par-dessus ce que
 * la file hors ligne du compte attend encore d'envoyer.
 *
 * Elle venait des seules props. Le rechargement et les boutons Retour et Avance
 * rendent pourtant une séance d'avant le vidage de la file, et une série
 * ajoutée et cochée sans réseau en disparaissait, sans `cloud_off`, jusqu'au
 * retour du réseau (#2048). Les rangées superposées portent l'identifiant de
 * leur entrée (`temp-q<queueId>`) : `useLignesDeLaSeance` et
 * `useAjoutEtRetraitDeSerie` les reconnaissent à leur montage et les suivent
 * jusqu'au vidage, comme celles qu'on vient de mettre en file.
 *
 * Retour et Avance rendent aussi les props de la visite, d'avant tout ce que la
 * page a fait accepter par l'API : l'entrée d'historique garde donc la séance
 * à jour (`garderDansLHistorique`), et c'est elle qui sert de séance de départ
 * quand elle existe. Elle ne peut l'être que de sa propre visite : une visite
 * plus ancienne de la même séance n'a pas l'exercice ajouté depuis, et la
 * superposition reconstruit alors la ligne d'une série en file d'après le
 * contexte de son entrée.
 *
 * Les brouillons orphelins sont effacés d'abord : un brouillon rangé sous un
 * identifiant provisoire d'un montage précédent désignerait une autre série.
 *
 * @param {{
 *   seance: object,
 *   exercices?: Array<object>,
 *   page?: object|null,
 *   valeursDeRepli?: () => Array<{id: unknown, champ: string, valeur: unknown}>,
 * }} ouverture la séance rendue par le serveur, les exercices du compte, la
 *   page d'Inertia (`usePage()`), dont l'entrée d'historique garde la séance,
 *   et les valeurs de repli des écritures qui attendent (`useBrouillonsDeSeries`)
 * @returns {{ localWorkout: import('vue').Ref<object>, seanceDeDepart: object }}
 *   la copie locale, et la séance d'où elle part, celle du serveur ou de l'historique
 */
export const useSeanceEtSaFile = ({ seance, exercices = [], page = null, valeursDeRepli = () => [] }) => {
    const seanceDeDepart = seanceGardee(page, seance) ?? seance
    const entrees = SyncService.entreesDuCompte()

    effacerLesBrouillonsOrphelins(entrees.map((entree) => identifiantEnFile(entree.id)))

    const localWorkout = ref(
        superposerLaFile(seanceDeDepart, entrees, {
            exercices: (exercices ?? []).filter((exercice) => exercice?.id),
        }),
    )

    if (page !== null && getCurrentInstance()) {
        garderDansLHistorique({ page, localWorkout, valeursDeRepli })
    }

    return { localWorkout, seanceDeDepart }
}

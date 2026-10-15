import { getCurrentInstance, onMounted, onUnmounted, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import SyncService from '@/Utils/SyncService'
import { identifiantEnFile, seanceConnueDuServeur, superposerLaFile } from '@/Utils/seanceEtFile'
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
 * rangées provisoires que la file rend (`seanceConnueDuServeur`).
 *
 * Le piège : `router.remember` écrit dans l'entrée COURANTE, et Inertia la
 * change avant que la page parte. Une visite qui mène ailleurs pousse la page
 * suivante avant de démonter celle-ci, et le bouton Retour change d'entrée
 * avant même qu'Inertia le sache. Écrit à ce moment, le souvenir de la séance
 * remplaçait la page suivante dans l'historique, adresse comprise. Il se garde
 * donc une dernière fois sur `beforeUpdate`, qu'Inertia émet avant de pousser
 * la page qui arrive, puis plus jamais, comme dès un `popstate` vers une autre
 * entrée. Une visite côté client (`router.push`, `router.replace`) n'émet pas
 * `beforeUpdate` : en ajouter une qui quitte cette page demande de figer ce
 * souvenir avant. Sans page d'Inertia (un test qui la simule), rien n'est gardé.
 *
 * @param {{ page: object, localWorkout: import('vue').Ref<object> }} seance
 */
const garderDansLHistorique = ({ page, localWorkout }) => {
    const composant = page.component
    const adresse = cheminDe(page.url)
    let figee = typeof composant !== 'string' || adresse === null
    let derniere = JSON.stringify(seanceConnueDuServeur(localWorkout.value))

    const garder = () => {
        if (figee || page.component !== composant || cheminDe(page.url) !== adresse) {
            return
        }

        const seance = seanceConnueDuServeur(localWorkout.value)
        const empreinte = JSON.stringify(seance)

        if (empreinte !== derniere) {
            derniere = empreinte
            router.remember(seance, SOUVENIR_DE_LA_SEANCE)
        }
    }

    const avantLaPageSuivante = (event) => {
        const suivante = event.detail?.page

        if (suivante?.component === composant && cheminDe(suivante?.url) === adresse) {
            return
        }

        garder()
        figee = true
    }

    const auRetourOuALAvance = (event) => {
        if (event.state?.page !== undefined) {
            figee = true
        }
    }

    watch(localWorkout, garder, { deep: true })

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
 * quand elle existe.
 *
 * Les brouillons orphelins sont effacés d'abord : un brouillon rangé sous un
 * identifiant provisoire d'un montage précédent désignerait une autre série.
 *
 * @param {{ seance: object, exercices?: Array<object>, page?: object|null }} ouverture
 *   la séance rendue par le serveur, les exercices du compte, et la page
 *   d'Inertia (`usePage()`), dont l'entrée d'historique garde la séance
 * @returns {{ localWorkout: import('vue').Ref<object>, seanceDeDepart: object }}
 *   la copie locale, et la séance d'où elle part, celle du serveur ou de l'historique
 */
export const useSeanceEtSaFile = ({ seance, exercices = [], page = null }) => {
    const seanceDeDepart = seanceGardee(page, seance) ?? seance
    const entrees = SyncService.entreesDuCompte()

    effacerLesBrouillonsOrphelins(entrees.map((entree) => identifiantEnFile(entree.id)))

    const localWorkout = ref(
        superposerLaFile(seanceDeDepart, entrees, {
            exercices: (exercices ?? []).filter((exercice) => exercice?.id),
        }),
    )

    if (page !== null && getCurrentInstance()) {
        garderDansLHistorique({ page, localWorkout })
    }

    return { localWorkout, seanceDeDepart }
}

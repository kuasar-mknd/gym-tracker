import { ref } from 'vue'
import SyncService from '@/Utils/SyncService'
import { identifiantEnFile, superposerLaFile } from '@/Utils/seanceEtFile'
import { effacerLesBrouillonsOrphelins } from '@/composables/useBrouillonsDeSeries'

/**
 * La copie locale de la séance, à l'ouverture de la page : celle que le serveur
 * a rendue, et par-dessus ce que la file hors ligne du compte attend encore
 * d'envoyer.
 *
 * Elle venait des seules props. Le rechargement et les boutons Retour et Avance
 * rendent pourtant une séance d'avant le vidage de la file, et une série
 * ajoutée et cochée sans réseau en disparaissait, sans `cloud_off`, jusqu'au
 * retour du réseau (#2048). Les rangées superposées portent l'identifiant de
 * leur entrée (`temp-q<queueId>`) : `useLignesDeLaSeance` et
 * `useAjoutEtRetraitDeSerie` les reconnaissent à leur montage et les suivent
 * jusqu'au vidage, comme celles qu'on vient de mettre en file.
 *
 * Les brouillons orphelins sont effacés d'abord : un brouillon rangé sous un
 * identifiant provisoire d'un montage précédent désignerait une autre série.
 *
 * @param {{ seance: object, exercices?: Array<object> }} page la séance rendue
 *   par le serveur, et les exercices du compte
 * @returns {import('vue').Ref<object>}
 */
export const useSeanceEtSaFile = ({ seance, exercices = [] }) => {
    const entrees = SyncService.entreesDuCompte()

    effacerLesBrouillonsOrphelins(entrees.map((entree) => identifiantEnFile(entree.id)))

    return ref(superposerLaFile(seance, entrees, { exercices: (exercices ?? []).filter((exercice) => exercice?.id) }))
}

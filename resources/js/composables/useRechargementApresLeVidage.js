import { getCurrentInstance, onMounted, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'
import SyncService from '@/Utils/SyncService'

/**
 * Recharge la séance une fois que la file hors ligne a envoyé ce que cette page
 * n'a pas écrit elle-même.
 *
 * Au chargement, le serveur rend la séance AVANT que la file se vide : le vidage
 * part ensuite, dans le constructeur de `SyncService`. L'exercice ajouté hors
 * ligne et ses séries arrivaient bien au serveur, mais la page rechargée ne les
 * montrait pas tant que rien ne rafraîchissait ses props, et la personne, qui
 * voyait son exercice disparu, le rajoutait (#1960, #1962). Les écritures que la
 * page fait elle-même, elle les suit déjà (`attentesDeRejeu`, l'écran tient leur
 * valeur) : elles ne rechargent rien, pour ne pas reprendre au serveur une valeur
 * en pleine saisie.
 *
 * Deux cas rendent les props anciennes : une écriture aboutie entre la dernière
 * réponse du serveur et le montage de la page, et une écriture déjà en file au
 * montage, qui aboutit ensuite. Le rechargement attend que la file du compte soit
 * vide, pour n'en faire qu'un par vidage ; la fusion des props garde les rangées
 * que le serveur ne connaît pas encore.
 *
 * @param {{ recharger?: () => void }} options `recharger` redemande la séance
 *   au serveur ; par défaut, ses seules props `workout`.
 */
export const useRechargementApresLeVidage = ({
    recharger = () => router.reload({ only: ['workout'], preserveScroll: true }),
} = {}) => {
    /** Les écritures en file avant cette page : la page n'en attend aucune. */
    const anterieures = new Set(SyncService.identifiantsEnAttente())

    let aRecharger = SyncService.rejeuxDepuisLaDerniereVisite > 0

    const rechargerSiLaFileEstVide = () => {
        if (aRecharger && SyncService.enAttente() === 0) {
            aRecharger = false
            recharger()
        }
    }

    const surRejeu = (event) => {
        if (anterieures.has(event.detail?.queueId)) {
            aRecharger = true
        }

        rechargerSiLaFileEstVide()
    }

    if (getCurrentInstance()) {
        onMounted(() => {
            window.addEventListener('sync:replayed', surRejeu)
            window.addEventListener('sync:failed', rechargerSiLaFileEstVide)
            window.addEventListener('sync:retired', rechargerSiLaFileEstVide)
            rechargerSiLaFileEstVide()
        })

        onUnmounted(() => {
            window.removeEventListener('sync:replayed', surRejeu)
            window.removeEventListener('sync:failed', rechargerSiLaFileEstVide)
            window.removeEventListener('sync:retired', rechargerSiLaFileEstVide)
        })
    }
}

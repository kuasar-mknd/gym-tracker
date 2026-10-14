import { EVENEMENT_SANS_RESEAU } from '@/Utils/visitesHorsLigne'

/**
 * Ce que le filet des visites hors ligne annonce pendant `geste` (#2048) : le
 * message de chaque toast, dans l'ordre, et ce que le geste rend.
 *
 * Le test appelle d'ordinaire le rappel `onNetworkError` qu'une visite simulée
 * a reçu : Inertia n'émet pas l'évènement général quand ce rappel rend `false`.
 *
 * @template T
 * @param {() => T} geste
 * @returns {{ rendu: T, annonces: Array<string|null> }}
 */
export const annoncesPendant = (geste) => {
    const annonces = []
    const ecouter = (evenement) => annonces.push(evenement.detail.message)
    window.addEventListener(EVENEMENT_SANS_RESEAU, ecouter)

    try {
        return { rendu: geste(), annonces }
    } finally {
        window.removeEventListener(EVENEMENT_SANS_RESEAU, ecouter)
    }
}

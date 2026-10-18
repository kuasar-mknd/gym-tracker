import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest'

import { chargerSyncService, retirerLesEcouteursDuService } from './fileHorsLigne'
import { fetchSimule, reseau } from './reseauSimule'

/*
 * Ce que la copie hors ligne de la séance attend avant de se recharger au
 * retour du réseau (#2048, partie E) : `seanceEnregistreeDansLaFile`, sur la
 * vraie file hors ligne. Rechargée avant que la file se vide, la séance
 * reprendrait au serveur une séance d'avant ses propres écritures.
 */

let file
let seanceEnregistreeDansLaFile

beforeAll(async () => {
    vi.stubGlobal('fetch', fetchSimule)
    reseau.mode = 'coupe'
    localStorage.clear()

    // La file d'abord : le module des visites la charge à la demande, dans le même graphe.
    file = await chargerSyncService({ compte: 7 })
    ;({ seanceEnregistreeDansLaFile } = await import('@/Utils/visitesHorsLigne'))
})

afterAll(() => {
    retirerLesEcouteursDuService()
    vi.unstubAllGlobals()
})

describe('la séance enregistrée', () => {
    it('l’est quand la file est vide et rien en vol, pas quand une écriture attend, et de nouveau après le rejeu', async () => {
        expect(await seanceEnregistreeDansLaFile()).toBe(true)

        // Sans réseau, une série part en file.
        await file.post('/api/v1/sets', { workout_line_id: 3, weight: 82.5, reps: 7 }).catch(() => {})

        expect(file.enAttente()).toBe(1)
        expect(await seanceEnregistreeDansLaFile()).toBe(false)

        // Le réseau revient : l'attente vide la file avant de conclure.
        reseau.mode = 'repond'
        reseau.statutDeFetch = 204

        expect(await seanceEnregistreeDansLaFile()).toBe(true)
        expect(file.enAttente()).toBe(0)
    })
})

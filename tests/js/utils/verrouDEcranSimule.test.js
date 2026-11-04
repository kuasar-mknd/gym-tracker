import { describe, it, expect, afterEach, beforeEach } from 'vitest'

import {
    cacherLaPage,
    demandes,
    installerLeVerrouDEcran,
    montrerLaPage,
    reglerLesDemandes,
    retirerLeVerrouDEcran,
    verrouDEcran,
    verrous,
    verrousActifs,
} from './verrouDEcranSimule'

/**
 * Le verrou d'écran factice (#2113) tient lui-même ce que les tests du
 * minuteur supposent du navigateur : un faux plus indulgent, qui garderait un
 * verrou sur une page cachée ou le rendrait à une page qui s'est cachée pendant
 * la demande, laisserait passer un minuteur qui ne le redemande pas au retour.
 */

beforeEach(() => installerLeVerrouDEcran())
afterEach(() => retirerLeVerrouDEcran())

describe('le verrou d’écran factice', () => {
    it('rend une sentinelle sur une page visible, la range, et la relâche une seule fois', async () => {
        const sentinelle = await navigator.wakeLock.request('screen')
        let annonces = 0
        sentinelle.addEventListener('release', () => {
            annonces += 1
        })

        expect(demandes).toEqual([{ type: 'screen', visibilite: 'visible' }])
        expect(verrous).toEqual([sentinelle])
        expect(sentinelle).toMatchObject({ type: 'screen', released: false })

        await sentinelle.release()
        await sentinelle.release()

        expect(sentinelle).toMatchObject({ released: true, relachePar: 'page' })
        expect(annonces).toBe(1)
        expect(verrousActifs()).toEqual([])
    })

    it('refuse un type inconnu, une page cachée, et ce que le navigateur refuse de lui-même', async () => {
        await expect(navigator.wakeLock.request('system')).rejects.toBeInstanceOf(TypeError)

        cacherLaPage()
        await expect(navigator.wakeLock.request('screen')).rejects.toMatchObject({ name: 'NotAllowedError' })

        montrerLaPage()
        verrouDEcran.refuser = true
        await expect(navigator.wakeLock.request('screen')).rejects.toMatchObject({ name: 'NotAllowedError' })

        expect(verrous).toEqual([])
    })

    it('relâche chaque verrou quand la page se cache, et l’annonce sur la sentinelle et sur le document', async () => {
        const sentinelle = await navigator.wakeLock.request('screen')
        const annonces = []
        sentinelle.addEventListener('release', () => annonces.push('release'))
        const noter = () => annonces.push(`visibilitychange:${document.visibilityState}`)
        document.addEventListener('visibilitychange', noter)

        cacherLaPage()
        montrerLaPage()
        document.removeEventListener('visibilitychange', noter)

        expect(sentinelle).toMatchObject({ released: true, relachePar: 'systeme' })
        expect(annonces).toEqual(['release', 'visibilitychange:hidden', 'visibilitychange:visible'])
        expect(document.visibilityState).toBe('visible')
        expect(document.hidden).toBe(false)
    })

    it('retient une demande jusqu’à son règlement, et la refuse si la page s’est cachée entre-temps', async () => {
        verrouDEcran.retenir = true
        let rendue = null
        navigator.wakeLock.request('screen').then((sentinelle) => {
            rendue = sentinelle
        })
        const seconde = navigator.wakeLock.request('screen')

        await Promise.resolve()
        expect(rendue).toBeNull()

        reglerLesDemandes()
        await Promise.resolve()
        expect(rendue).toBe(verrous[0])

        const retenue = navigator.wakeLock.request('screen')
        cacherLaPage()
        reglerLesDemandes()

        await expect(retenue).rejects.toMatchObject({ name: 'NotAllowedError' })
        await expect(seconde).resolves.toBe(verrous[1])
        expect(verrous).toHaveLength(2)
    })

    it('se retire sans laisser ni l’API ni la visibilité simulée', () => {
        cacherLaPage()

        retirerLeVerrouDEcran()

        expect('wakeLock' in navigator).toBe(false)
        expect(Object.getOwnPropertyDescriptor(document, 'visibilityState')).toBeUndefined()
        expect(document.visibilityState).toBe('visible')

        installerLeVerrouDEcran({ sansApi: true })

        expect('wakeLock' in navigator).toBe(false)
    })
})

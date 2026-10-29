import { describe, it, expect, afterEach, beforeEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import {
    SortieAudioWebKit,
    dansUnGeste,
    installerLaSortieWebKit,
    retirerLaSortieWebKit,
    sorties,
} from './sortieAudioWebKit'

/**
 * La sortie factice de WebKit (#2112) tient elle-même ce que les tests de la
 * sortie audio supposent d'elle : un faux plus indulgent que l'appareil laisse
 * passer un son qui se tairait sur un iPhone.
 *
 * L'état d'une sortie ne change qu'à la résolution de `resume()`, comme dans
 * WebKit, qui le pose dans une tâche mise en file : le faux le changeait
 * pendant l'appel. Un `jouer` qui lisait l'état juste après sa reprise, sans
 * attendre la promesse, sonnait alors sous lui, et se serait tu sur un iPhone
 * après une interruption d'iOS.
 */

/** Un son relié à la destination, démarré tout de suite ; rend ce que la sortie en retient. */
const sonSur = (sortie) => {
    const oscillateur = sortie.createOscillator()
    oscillateur.connect(sortie.destination)
    oscillateur.start()
    oscillateur.stop(sortie.currentTime + 0.1)

    return oscillateur.son
}

/** Une sortie neuve, créée dans un geste. */
const sortieNeuve = () => dansUnGeste(() => new window.AudioContext())

beforeEach(() => installerLaSortieWebKit())
afterEach(() => retirerLaSortieWebKit())

describe('la sortie factice de WebKit', () => {
    it('naît suspendue, même créée dans un geste, et se range dans les sorties', async () => {
        const sortie = await sortieNeuve()

        expect(sortie.state).toBe('suspended')
        expect(sortie.creeeDansUnGeste).toBe(true)
        expect(sorties).toEqual([sortie])
    })

    it.each(['suspended', 'interrupted'])(
        'reprise dans un geste, une sortie %s ne tourne qu’à la résolution de la promesse, pas pendant l’appel',
        async (etat) => {
            const sortie = await sortieNeuve()
            sortie.state = etat
            let pendantLAppel
            let aLaResolution

            await dansUnGeste(() => {
                const reprise = sortie.resume().then(() => {
                    aLaResolution = sortie.state
                })
                pendantLAppel = sortie.state

                return reprise
            })

            expect(pendantLAppel).toBe(etat)
            expect(aLaResolution).toBe('running')
            expect(sortie.reprises).toEqual([{ dansUnGeste: true, etat }])
        },
    )

    it('reprise hors geste par un navigateur qui le permet, ne tourne aussi qu’à la résolution', async () => {
        SortieAudioWebKit.repriseHorsGeste = true
        const sortie = new window.AudioContext()

        const reprise = sortie.resume()

        expect(sortie.state).toBe('suspended')
        await reprise
        expect(sortie.state).toBe('running')
    })

    it('reprise hors geste dans WebKit, reste arrêtée et attend le geste suivant, qui la résout', async () => {
        const sortie = new window.AudioContext()
        let resolue = false

        sortie.resume().then(() => {
            resolue = true
        })
        await flushPromises()

        expect(sortie.state).toBe('suspended')
        expect(resolue).toBe(false)

        await dansUnGeste(() => sortie.resume())
        await flushPromises()

        expect(sortie.state).toBe('running')
        expect(resolue).toBe(true)
    })

    it('fermée pendant sa reprise, reste fermée ; reprise une fois fermée, refuse', async () => {
        const sortie = await sortieNeuve()

        const reprise = dansUnGeste(() => sortie.resume())
        sortie.close()
        await reprise

        expect(sortie.state).toBe('closed')
        await expect(dansUnGeste(() => sortie.resume())).rejects.toThrow('La sortie est fermée.')
    })

    /*
     * Dans WebKit, un son qui démarre pendant un geste démarre sa sortie
     * suspendue (`sourceNodeWillBeginPlayback`), à la suite lui aussi : il
     * s'entend quelques millisecondes plus tard, dès qu'elle tourne, et ne
     * part pas à contretemps.
     */
    it('un son démarré dans un geste démarre sa sortie à la suite, et s’entend sans partir à contretemps', async () => {
        const sortie = await sortieNeuve()
        let pendantLAppel

        await dansUnGeste(() => {
            sonSur(sortie)
            pendantLAppel = sortie.state
        })
        await flushPromises()

        expect(pendantLAppel).toBe('suspended')
        expect(sortie.state).toBe('running')
        expect(sortie.sons).toEqual([expect.objectContaining({ audible: true, aContretemps: false })])
    })

    it('un son démarré dans le geste qui reprend sa sortie s’entend aussi dès qu’elle tourne', async () => {
        const sortie = await sortieNeuve()

        await dansUnGeste(() => {
            const reprise = sortie.resume()
            sonSur(sortie)

            return reprise
        })

        expect(sortie.state).toBe('running')
        expect(sortie.sons).toEqual([expect.objectContaining({ audible: true, aContretemps: false })])
    })

    it('un son démarré hors geste sur une sortie arrêtée ne s’entend pas, et part à contretemps quand un geste la reprend', async () => {
        const sortie = await sortieNeuve()

        const son = sonSur(sortie)
        await flushPromises()

        expect(sortie.state).toBe('suspended')
        expect(son).toMatchObject({ audible: false, aContretemps: false })

        await dansUnGeste(() => sortie.resume())

        expect(son).toMatchObject({ audible: false, aContretemps: true })
        expect(sortie.sonsAudibles).toEqual([])
    })

    it('un son démarré sur une sortie qui tourne s’entend tout de suite', async () => {
        const sortie = await sortieNeuve()
        await dansUnGeste(() => sortie.resume())

        const son = sonSur(sortie)

        expect(son).toMatchObject({ audible: true, aContretemps: false, fin: 0.1 })
    })
})

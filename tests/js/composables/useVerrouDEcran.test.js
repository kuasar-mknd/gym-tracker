import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'

import { useVerrouDEcran } from '@/composables/useVerrouDEcran'
import {
    SentinelleFactice,
    cacherLaPage,
    demandes,
    installerLeVerrouDEcran,
    montrerLaPage,
    reglerLesDemandes,
    retirerLeVerrouDEcran,
    verrouDEcran,
    verrous,
    verrousActifs,
} from '../utils/verrouDEcranSimule'

/**
 * Le verrou d'écran d'un composant (#2113) : gardé, il retient l'écran allumé ;
 * le navigateur le relâche quand la page se cache, et il se redemande à son
 * retour tant qu'il est voulu ; relâché, il ne revient pas. Le faux tient la
 * règle du navigateur (`tests/js/utils/verrouDEcranSimule.js`).
 */

/**
 * Chaque hôte monté par un cas, démonté après lui s'il ne l'est pas déjà : un
 * verrou laissé gardé écoute encore la page, et se redemanderait au retour de
 * la page d'un cas suivant.
 */
let hotes = []

/** Le composable pose sa libération sur le démontage : il lui faut un composant hôte. */
const monter = () => {
    let verrou
    const hote = mount(
        defineComponent({
            setup() {
                verrou = useVerrouDEcran()

                return () => h('div')
            },
        }),
    )
    hotes.push(hote)

    return { hote, verrou }
}

beforeEach(() => installerLeVerrouDEcran())

afterEach(() => {
    hotes.filter((hote) => !hote.vm.$.isUnmounted).forEach((hote) => hote.unmount())
    hotes = []
    vi.restoreAllMocks()
    retirerLeVerrouDEcran()
})

describe('garder et relâcher le verrou d’écran', () => {
    it('le demande une seule fois, même gardé deux fois, et le relâche', async () => {
        const { verrou } = monter()

        verrou.garder()
        verrou.garder()
        await flushPromises()

        expect(demandes).toEqual([{ type: 'screen', visibilite: 'visible' }])
        expect(verrousActifs()).toHaveLength(1)

        verrou.relacher()

        expect(verrous[0]).toMatchObject({ released: true, relachePar: 'page' })
        expect(verrousActifs()).toEqual([])
    })

    it('se redemande au retour de la page, que le navigateur l’ait relâché en la cachant', async () => {
        const { verrou } = monter()
        verrou.garder()
        await flushPromises()

        cacherLaPage()
        await flushPromises()

        expect(verrous[0]).toMatchObject({ released: true, relachePar: 'systeme' })
        expect(demandes).toHaveLength(1)

        montrerLaPage()
        await flushPromises()

        expect(demandes).toEqual([
            { type: 'screen', visibilite: 'visible' },
            { type: 'screen', visibilite: 'visible' },
        ])
        expect(verrousActifs()).toEqual([verrous[1]])
    })

    it('ne revient pas au retour de la page une fois relâché', async () => {
        const { verrou } = monter()
        verrou.garder()
        await flushPromises()
        verrou.relacher()

        cacherLaPage()
        montrerLaPage()
        await flushPromises()

        expect(demandes).toHaveLength(1)
        expect(verrousActifs()).toEqual([])
    })

    it('gardé sur une page cachée, attend son retour pour se demander', async () => {
        const { verrou } = monter()
        cacherLaPage()

        verrou.garder()
        await flushPromises()
        expect(demandes).toEqual([])

        montrerLaPage()
        await flushPromises()

        expect(demandes).toEqual([{ type: 'screen', visibilite: 'visible' }])
        expect(verrousActifs()).toHaveLength(1)
    })

    it('relâché avant que la demande ne soit rendue, relâche le verrou dès qu’il arrive', async () => {
        verrouDEcran.retenir = true
        const { verrou } = monter()

        verrou.garder()
        verrou.relacher()
        reglerLesDemandes()
        await flushPromises()

        expect(verrous).toHaveLength(1)
        expect(verrous[0]).toMatchObject({ released: true, relachePar: 'page' })
    })

    it('relâché puis regardé pendant la demande, garde le verrou qu’elle rend, sans en demander un second', async () => {
        verrouDEcran.retenir = true
        const { verrou } = monter()

        verrou.garder()
        verrou.relacher()
        verrou.garder()
        reglerLesDemandes()
        await flushPromises()

        expect(demandes).toHaveLength(1)
        expect(verrousActifs()).toEqual([verrous[0]])
    })

    it('refusé, ne lève pas, et se redemande au geste suivant comme au retour de la page', async () => {
        verrouDEcran.refuser = true
        const { verrou } = monter()

        expect(() => verrou.garder()).not.toThrow()
        await flushPromises()
        expect(verrous).toEqual([])

        verrouDEcran.refuser = false
        verrou.garder()
        await flushPromises()
        expect(verrousActifs()).toEqual([verrous[0]])

        cacherLaPage()
        montrerLaPage()
        await flushPromises()

        expect(demandes).toHaveLength(3)
        expect(verrousActifs()).toEqual([verrous[1]])
    })

    it('ne lève pas quand la demande lève elle-même, ni sans l’API', async () => {
        vi.spyOn(navigator.wakeLock, 'request').mockImplementation(() => {
            throw new TypeError('wakeLock indisponible')
        })
        const { verrou } = monter()

        expect(() => verrou.garder()).not.toThrow()
        await flushPromises()

        installerLeVerrouDEcran({ sansApi: true })
        const sansApi = monter().verrou

        expect(() => sansApi.garder()).not.toThrow()
        expect(() => sansApi.relacher()).not.toThrow()
    })

    /*
     * Le refus vient d'une sentinelle dont `release()` rend elle-même un rejet,
     * et non d'un espion : l'espion de Vitest suit la promesse qu'il rend et la
     * traiterait lui-même (`.ai/rules/tests-js.md`). Un rejet laissé sans
     * preneur ferait échouer la passe.
     */
    it('ne laisse aucun rejet non géré quand le navigateur refuse de relâcher', async () => {
        class SentinelleRebelle extends SentinelleFactice {
            release() {
                return Promise.reject(new DOMException('Refusé.', 'InvalidStateError'))
            }
        }
        vi.spyOn(navigator.wakeLock, 'request').mockImplementation(() =>
            Promise.resolve(new SentinelleRebelle('screen')),
        )
        const { verrou } = monter()
        verrou.garder()
        await flushPromises()

        verrou.relacher()
        await flushPromises()

        expect(verrous).toHaveLength(1)
    })

    it('se relâche au démontage, et n’écoute plus la page', async () => {
        const { hote, verrou } = monter()
        verrou.garder()
        await flushPromises()

        hote.unmount()

        expect(verrous[0]).toMatchObject({ released: true, relachePar: 'page' })

        cacherLaPage()
        montrerLaPage()
        await flushPromises()

        expect(demandes).toHaveLength(1)
    })
})

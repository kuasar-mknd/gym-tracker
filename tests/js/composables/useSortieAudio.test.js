import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'

import { DELAI_DE_REPRISE_MS, useSortieAudio } from '@/composables/useSortieAudio'
import {
    SortieAudioWebKit,
    dansUnGeste,
    installerLaSortieWebKit,
    retirerLaSortieWebKit,
    sonsAudibles,
    sorties,
} from '../utils/sortieAudioWebKit'

/**
 * La sortie audio d'une page (#2112) : ouverte ou reprise dans un geste, jouée
 * ensuite hors geste, rendue au démontage. La sortie factice applique la règle
 * de WebKit : créée ou reprise hors geste, elle reste suspendue.
 */

/** Le composable pose sa fermeture sur le démontage : il lui faut un composant hôte. */
const monter = () => {
    let sortie
    const hote = mount(
        defineComponent({
            setup() {
                sortie = useSortieAudio()

                return () => h('div')
            },
        }),
    )

    return { hote, sortie }
}

/** Un son de test : un oscillateur relié à la destination, démarré tout de suite. */
const bip = (contexte) => {
    const oscillateur = contexte.createOscillator()
    oscillateur.frequency.value = 660
    oscillateur.connect(contexte.destination)
    oscillateur.start()
    oscillateur.stop(contexte.currentTime + 0.1)
}

beforeEach(() => installerLaSortieWebKit())

afterEach(() => {
    vi.restoreAllMocks()
    vi.useRealTimers()
    retirerLaSortieWebKit()
})

describe('ouvrir la sortie dans le geste', () => {
    it('crée la sortie dans le geste, une seule fois, et la garde d’un geste à l’autre', async () => {
        const { sortie } = monter()

        const premiere = await dansUnGeste(() => sortie.preparer())
        const seconde = await dansUnGeste(() => sortie.preparer())

        expect(sorties).toHaveLength(1)
        expect(premiere).toBe(sorties[0])
        expect(seconde).toBe(premiere)
        expect(premiere.creeeDansUnGeste).toBe(true)
        expect(premiere.state).toBe('running')
    })

    it('prend AudioContext quand il existe, et webkitAudioContext seul sinon', async () => {
        class Prefixee extends SortieAudioWebKit {}
        window.webkitAudioContext = Prefixee
        const avecLesDeux = monter().sortie

        expect(await dansUnGeste(() => avecLesDeux.preparer())).not.toBeInstanceOf(Prefixee)

        installerLaSortieWebKit({ prefixee: true })
        const prefixeeSeule = monter().sortie

        expect(await dansUnGeste(() => prefixeeSeule.preparer())).toBe(sorties[0])
        expect(sorties[0].state).toBe('running')
    })

    it.each(['suspended', 'interrupted'])('reprend dans le geste une sortie %s', async (etat) => {
        const { sortie } = monter()
        await dansUnGeste(() => sortie.preparer())
        sorties[0].state = etat

        await dansUnGeste(() => sortie.preparer())

        expect(sorties).toHaveLength(1)
        expect(sorties[0].reprises).toEqual([{ dansUnGeste: true, etat }])
        expect(sorties[0].state).toBe('running')
    })

    it('ne reprend pas une sortie qui tourne', async () => {
        const { sortie } = monter()

        await dansUnGeste(() => sortie.preparer())
        await dansUnGeste(() => sortie.preparer())

        expect(sorties[0].reprises).toEqual([])
    })

    it('recrée dans le geste une sortie fermée', async () => {
        const { sortie } = monter()
        await dansUnGeste(() => sortie.preparer())
        sorties[0].close()

        const nouvelle = await dansUnGeste(() => sortie.preparer())

        expect(sorties).toHaveLength(2)
        expect(nouvelle).toBe(sorties[1])
        expect(nouvelle.creeeDansUnGeste).toBe(true)
        expect(nouvelle.state).toBe('running')
    })
})

describe('jouer hors geste', () => {
    it('joue tout de suite sur une sortie qui tourne', async () => {
        const { sortie } = monter()
        await dansUnGeste(() => sortie.preparer())

        sortie.jouer(bip)

        expect(sonsAudibles()).toEqual([expect.objectContaining({ frequence: 660, aContretemps: false })])
    })

    it('n’ouvre aucune sortie : sans geste préalable, rien ne joue', () => {
        const { sortie } = monter()
        const construire = vi.fn(bip)

        sortie.jouer(construire)

        expect(sorties).toHaveLength(0)
        expect(construire).not.toHaveBeenCalled()
    })

    it('ne joue rien sur une sortie fermée', async () => {
        const { sortie } = monter()
        await dansUnGeste(() => sortie.preparer())
        sorties[0].close()
        const construire = vi.fn(bip)

        sortie.jouer(construire)
        await flushPromises()

        expect(construire).not.toHaveBeenCalled()
        expect(sorties).toHaveLength(1)
    })

    it('reprend une sortie arrêtée que le navigateur laisse reprendre hors geste, et joue à la reprise', async () => {
        SortieAudioWebKit.repriseHorsGeste = true
        const { sortie } = monter()
        await dansUnGeste(() => sortie.preparer())
        sorties[0].state = 'suspended'

        sortie.jouer(bip)
        await flushPromises()

        expect(sorties[0].reprises.at(-1)).toEqual({ dansUnGeste: false, etat: 'suspended' })
        expect(sonsAudibles()).toHaveLength(1)
        expect(sorties[0].sons.filter((son) => son.aContretemps)).toEqual([])
    })

    it('ne laisse rien partir à contretemps quand la sortie arrêtée ne reprend qu’au geste suivant', async () => {
        vi.useFakeTimers({ toFake: ['Date'] })
        const { sortie } = monter()
        await dansUnGeste(() => sortie.preparer())
        sorties[0].interrompre()

        // La fin du repos tombe pendant l'interruption : rien ne peut sonner.
        sortie.jouer(bip)
        await flushPromises()
        expect(sorties[0].sons).toEqual([])

        // La série suivante, plus tard : la sortie reprend, et la fin passée ne sonne pas.
        vi.setSystemTime(Date.now() + DELAI_DE_REPRISE_MS + 1)
        await dansUnGeste(() => sortie.preparer())
        await flushPromises()

        expect(sorties[0].state).toBe('running')
        expect(sorties[0].sons).toEqual([])
    })
})

describe('ce qui échoue ne remonte jamais au geste', () => {
    it('sans Web Audio : ni sortie, ni erreur, ni avertissement', async () => {
        retirerLaSortieWebKit()
        const avertissement = vi.spyOn(console, 'warn')
        const { sortie } = monter()

        expect(await dansUnGeste(() => sortie.preparer())).toBeNull()
        sortie.jouer(bip)

        expect(avertissement).not.toHaveBeenCalled()
    })

    it('un constructeur qui lève laisse le geste aller à son terme', async () => {
        const avertissement = vi.spyOn(console, 'warn').mockImplementation(() => {})
        window.AudioContext = class {
            constructor() {
                throw new Error('aucun périphérique de sortie')
            }
        }
        const { sortie } = monter()

        expect(await dansUnGeste(() => sortie.preparer())).toBeNull()
        expect(avertissement).toHaveBeenCalled()
    })

    it('une reprise refusée ou qui lève ne remonte ni au geste ni en rejet non géré', async () => {
        const { sortie } = monter()
        await dansUnGeste(() => sortie.preparer())
        sorties[0].state = 'suspended'
        const reprise = vi
            .spyOn(SortieAudioWebKit.prototype, 'resume')
            .mockRejectedValueOnce(new DOMException('refusée', 'NotAllowedError'))
            .mockRejectedValueOnce(new DOMException('refusée', 'NotAllowedError'))
            .mockImplementationOnce(() => {
                throw new TypeError('resume absent')
            })

        await dansUnGeste(() => sortie.preparer())
        sortie.jouer(bip)
        await dansUnGeste(() => sortie.preparer())
        await flushPromises()

        expect(reprise).toHaveBeenCalledTimes(3)
        expect(sorties[0].sons).toEqual([])
    })

    it('un son qui échoue à se construire laisse la minuterie aller à son terme', async () => {
        const avertissement = vi.spyOn(console, 'warn').mockImplementation(() => {})
        const { sortie } = monter()
        await dansUnGeste(() => sortie.preparer())

        expect(() =>
            sortie.jouer(() => {
                throw new Error('aucun oscillateur')
            }),
        ).not.toThrow()
        expect(avertissement).toHaveBeenCalled()
    })
})

describe('rendre la sortie', () => {
    it('la rend au démontage du composant qui l’a demandée, une fois, et n’en rouvre aucune ensuite', async () => {
        const { hote, sortie } = monter()
        await dansUnGeste(() => sortie.preparer())

        hote.unmount()
        sortie.jouer(bip)

        expect(sorties).toHaveLength(1)
        expect(sorties[0].fermetures).toBe(1)
        expect(sorties[0].state).toBe('closed')
        expect(sorties[0].sons).toEqual([])
    })

    it('n’a rien à rendre quand aucun geste ne l’a ouverte, ni quand elle est déjà fermée', async () => {
        monter().hote.unmount()
        expect(sorties).toHaveLength(0)

        const { hote, sortie } = monter()
        await dansUnGeste(() => sortie.preparer())
        sorties[0].close()
        hote.unmount()

        expect(sorties[0].fermetures).toBe(1)
    })

    it('une fermeture refusée ne devient pas un rejet non géré', async () => {
        vi.spyOn(SortieAudioWebKit.prototype, 'close').mockRejectedValue(new DOMException('déjà fermée', 'InvalidStateError'))
        const { hote, sortie } = monter()
        await dansUnGeste(() => sortie.preparer())

        hote.unmount()
        await flushPromises()

        expect(SortieAudioWebKit.prototype.close).toHaveBeenCalledTimes(1)
    })

    it('hors d’un composant, ne pose aucune fermeture, et ne le reproche pas', () => {
        const avertissement = vi.spyOn(console, 'warn')

        useSortieAudio()

        expect(avertissement).not.toHaveBeenCalled()
    })
})

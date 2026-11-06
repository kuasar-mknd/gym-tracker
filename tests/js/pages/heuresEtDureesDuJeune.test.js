import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import { poserLeFuseau } from '../utils/fuseauDeLAppareil'

/*
 * Les durées et les heures que la page du jeûne affiche, là où
 * `fastingIndex.test.js` ne regarde pas (#2189) : la seconde qui n'est pas
 * encore écoulée, après le début comme avant un début à venir, un appareil à
 * Paris, et la nuit où Toronto répète une heure.
 *
 * Une durée est l'écart réel entre deux instants, en secondes pleines, quel
 * que soit le fuseau : elle ne se lit pas sur les heures murales, qui comptent
 * une heure de moins la nuit du passage à l'heure d'hiver. Une date s'écrit
 * `JJ/MM/AAAA HH:mm`, à l'heure de l'appareil. Ces cas passaient avec dayjs, et
 * passent sans lui.
 */

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue')

    return {
        Head: { template: '<div />' },
        Link: { template: '<a><slot /></a>' },
        router: { visit: vi.fn(), delete: vi.fn() },
        useForm: (champs) =>
            reactive({ ...champs, errors: {}, processing: false, transform: vi.fn(), post: vi.fn(), patch: vi.fn() }),
    }
})

// Le graphique n'a rien à voir ici, et chargerait Chart.js.
vi.mock('@/Components/Stats/FastingHistoryChart.vue', () => ({
    __esModule: true,
    default: { name: 'FastingHistoryChart', render: () => null },
}))

const PageDuJeune = (await import('@/Pages/Tools/Fasting/Index.vue')).default

const historiqueVide = { data: [], current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null }

/** L'instant de `fastingIndex.test.js` : 12:00Z le 10 mars 2026, 13:00 à Paris (UTC+1). */
const NOW = new Date('2026-03-10T12:00:00Z')

const montees = []

/**
 * @param {{ activeFast?: object|null, history?: object[] }} props
 */
const monterLaPage = async ({ activeFast = null, history = [] } = {}) => {
    const page = mount(PageDuJeune, {
        props: { activeFast, history: { ...historiqueVide, data: history } },
        global: {
            directives: { press: {} },
            stubs: { AuthenticatedLayout: { template: '<div><slot /></div>' } },
        },
    })
    montees.push(page)

    await flushPromises()

    return page
}

const ecoule = (page) => page.find('.font-mono.text-4xl').text()

/** Le décalage du trait de l'anneau : sa circonférence entière tant que rien n'est écoulé. */
const decalageDeLAnneau = (page) => Number(page.get('circle[stroke-dasharray]').attributes('stroke-dashoffset'))

const ANNEAU_VIDE = 2 * Math.PI * 45

/** Le texte de chaque ligne de l'historique détaillé, dans l'ordre. */
const lignesDeLHistorique = (page) => page.findAll('.space-y-3 > div').map((ligne) => ligne.text())

/** Un jeûne de seize heures, tel qu'Eloquent le sert : un instant ISO, à la seconde. */
const jeune = (id, debut, fin = null) => ({
    id,
    type: '16:8',
    start_time: debut,
    end_time: fin,
    target_duration_minutes: 16 * 60,
})

beforeAll(() => {
    globalThis.route = (nom, parametre) => `/${nom}${parametre === undefined ? '' : `/${parametre}`}`
})

beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(NOW)
})

afterEach(() => {
    montees.splice(0).forEach((page) => page.unmount())
    vi.useRealTimers()
})

describe('Le jeûne — une seconde ne compte qu’une fois écoulée', () => {
    it('affiche 01:59:59 à 7 199,6 secondes du début, puis 02:00:00 à la seconde suivante', async () => {
        // L'instant de l'appareil porte ses millisecondes, le début servi n'en a pas.
        vi.setSystemTime(new Date('2026-03-10T12:00:00.600Z'))
        const page = await monterLaPage({ activeFast: jeune(7, '2026-03-10T10:00:01.000000Z') })

        expect(ecoule(page)).toBe('01:59:59')
        expect(page.text()).toContain('14:00:01 restants')

        vi.advanceTimersByTime(1000)
        await flushPromises()

        expect(ecoule(page)).toBe('02:00:00')
        expect(page.text()).toContain('14:00:00 restants')
    })

    it('affiche 00:00:00 pendant la seconde qui précède un début à venir, comme dayjs, puis compte depuis le début', async () => {
        // Un début tapé en avance, ou venu d'un appareil dont l'horloge avance, tombe ici 0,4 s après l'instant de
        // l'appareil. L'écart se tronque vers zéro, comme `diff(…, 'second')` de dayjs : arrondi vers le bas, il
        // affichait « -1:-1:-1 » et « 16:00:01 restants », et l'anneau reculait sous zéro.
        vi.setSystemTime(new Date('2026-03-10T12:00:00.600Z'))
        const page = await monterLaPage({ activeFast: jeune(12, '2026-03-10T12:00:01.000000Z') })

        expect(ecoule(page)).toBe('00:00:00')
        expect(page.text()).toContain('16:00:00 restants')
        expect(decalageDeLAnneau(page)).toBe(ANNEAU_VIDE)

        vi.advanceTimersByTime(1000)
        await flushPromises()

        expect(ecoule(page)).toBe('00:00:00')
        expect(page.text()).toContain('16:00:00 restants')

        vi.advanceTimersByTime(1000)
        await flushPromises()

        expect(ecoule(page)).toBe('00:00:01')
        expect(page.text()).toContain('15:59:59 restants')
    })
})

describe('Le jeûne — un début à venir (#2198)', () => {
    /*
     * Un début postérieur à l'instant de l'appareil : une heure venue d'un appareil dont l'horloge avance, ou un
     * jeûne enregistré au futur avant que le serveur ne le refuse. L'écoulé était compté sans borne basse, et chaque
     * champ s'affichait avec son signe : « -1:-30:00 » et « 16:30:00 restants », plus que l'objectif, à la seconde
     * ronde, « -1:-30:-59 » et « 16:29:59 restants » à 600 ms, l'anneau reculant sous zéro.
     */
    const DEBUT = '2026-03-10T12:30:00.000000Z'

    it.each([
        ['à la seconde ronde', '2026-03-10T12:00:00.000Z'],
        ['à 600 ms', '2026-03-10T12:00:00.600Z'],
    ])(
        'attend à 00:00:00, l’anneau vide et l’objectif entier devant, un début trente minutes en avance (%s), puis compte depuis le début',
        async (_cas, instant) => {
            vi.setSystemTime(new Date(instant))
            const page = await monterLaPage({ activeFast: jeune(13, DEBUT) })

            expect(ecoule(page)).toBe('00:00:00')
            expect(page.text()).toContain('16:00:00 restants')
            expect(decalageDeLAnneau(page)).toBe(ANNEAU_VIDE)

            // La seconde qui précède le début : toujours rien d'écoulé.
            vi.advanceTimersByTime(1799 * 1000)
            await flushPromises()

            expect(ecoule(page)).toBe('00:00:00')
            expect(page.text()).toContain('16:00:00 restants')
            expect(decalageDeLAnneau(page)).toBe(ANNEAU_VIDE)
            expect(page.text()).not.toMatch(/-\d/)

            // Deux secondes après le début, le compteur est parti.
            vi.advanceTimersByTime(3000)
            await flushPromises()

            expect(ecoule(page)).toBe('00:00:02')
            expect(page.text()).toContain('15:59:58 restants')
            expect(decalageDeLAnneau(page)).toBeLessThan(ANNEAU_VIDE)
        },
    )

    it('donne une durée nulle, pas négative, au jeûne terminé avant son début', async () => {
        // Commencé sur un appareil en avance de cinq minutes, terminé une minute plus tard sur un autre.
        const page = await monterLaPage({
            history: [jeune(14, '2026-03-10T11:05:00.000000Z', '2026-03-10T11:01:00.000000Z')],
        })

        const [ligne] = lignesDeLHistorique(page)

        expect(ligne).toContain('00:00:00')
        expect(ligne).not.toMatch(/-\d/)
    })
})

describe('Le jeûne — un appareil à Paris', () => {
    beforeEach(() => poserLeFuseau('Europe/Paris', -60, NOW))

    it('date le jeûne en cours et l’historique à l’heure de Paris, et compte seize heures', async () => {
        const page = await monterLaPage({
            activeFast: jeune(8, '2026-03-10T12:00:00.000000Z'),
            history: [jeune(7, '2026-03-09T11:00:00.000000Z', '2026-03-10T03:00:00.000000Z')],
        })

        const [ligne] = lignesDeLHistorique(page)

        expect(page.text()).toContain('Débuté : 10/03/2026 13:00')
        expect(ligne).toContain('09/03/2026 12:00 - 10/03/2026 04:00')
        expect(ligne).toContain('16:00:00')
        expect(page.find('[aria-label^="Supprimer le jeûne"]').attributes('aria-label')).toBe(
            'Supprimer le jeûne du 09/03/2026 12:00',
        )
    })
})

describe('Le jeûne — un appareil à Toronto la nuit du passage à l’heure d’hiver', () => {
    // 06:30Z le 1er novembre 2026 : 01:30 EST, la seconde fois que Toronto lit
    // 01:30 cette nuit-là ; la première, 01:30 EDT, était 05:30Z.
    const SECONDE_OCCURRENCE = new Date('2026-11-01T06:30:00Z')

    beforeEach(() => {
        vi.setSystemTime(SECONDE_OCCURRENCE)

        return poserLeFuseau('America/Toronto', 300, SECONDE_OCCURRENCE)
    })

    it('compte deux heures depuis 00:30, quand l’horloge murale n’en a avancé que d’une', async () => {
        const page = await monterLaPage({ activeFast: jeune(9, '2026-11-01T04:30:00.000000Z') })

        expect(page.text()).toContain('Débuté : 01/11/2026 00:30')
        expect(ecoule(page)).toBe('02:00:00')
        expect(page.text()).toContain('14:00:00 restants')
    })

    it('donne à chaque jeûne terminé sa durée réelle, l’heure répétée comprise', async () => {
        const page = await monterLaPage({
            history: [
                // 18:00 EDT le 31 octobre, 09:00 EST le 1er novembre : quinze heures murales, seize réelles.
                jeune(10, '2026-10-31T22:00:00.000000Z', '2026-11-01T14:00:00.000000Z'),
                // De la première 01:30 à la seconde : la même heure murale, une heure réelle.
                jeune(11, '2026-11-01T05:30:00.000000Z', '2026-11-01T06:30:00.000000Z'),
            ],
        })

        const lignes = lignesDeLHistorique(page)

        expect(lignes).toHaveLength(2)
        expect(lignes[0]).toContain('31/10/2026 18:00 - 01/11/2026 09:00')
        expect(lignes[0]).toContain('16:00:00')
        expect(lignes[1]).toContain('01/11/2026 01:30 - 01/11/2026 01:30')
        expect(lignes[1]).toContain('01:00:00')
    })
})

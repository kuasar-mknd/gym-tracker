import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

/**
 * The fasting page is a clock: it turns a start timestamp into an elapsed
 * counter, a remaining counter and a progress arc, and it turns the chosen
 * preset into the payload the server validates. Both are asserted here on
 * values with a fractional part and a non-zero hour, so a wrong divisor or a
 * dropped zero-pad cannot survive.
 */
const hoisted = vi.hoisted(() => ({
    post: vi.fn(),
    patch: vi.fn(),
    visit: vi.fn(),
    destroy: vi.fn(),
}))

vi.mock('vue-chartjs', async () => (await import('../components/chartRecorder.js')).chartRecorders())

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue')

    return {
        Head: { template: '<div />' },
        Link: { template: '<a><slot /></a>' },
        router: {
            visit: (...args) => hoisted.visit(...args),
            delete: (...args) => hoisted.destroy(...args),
            reload: vi.fn(),
        },
        useForm: (fields) => {
            const keys = Object.keys(fields)
            const payload = () => Object.fromEntries(keys.map((key) => [key, form[key]]))
            let transformer = (data) => data

            const form = reactive({
                ...fields,
                errors: {},
                processing: false,
                transform(fn) {
                    transformer = fn

                    return form
                },
                post: (url) => hoisted.post(url, transformer(payload())),
                patch: (url) => hoisted.patch(url, transformer(payload())),
                reset: vi.fn(),
            })

            return form
        },
    }
})

// The chart is a defineAsyncComponent behind a dynamic import; loaded first so
// the page's own import resolves from the module cache inside flushPromises.
await import('@/Components/Stats/FastingHistoryChart.vue')

const FastingIndex = (await import('@/Pages/Tools/Fasting/Index.vue')).default

const NOW = new Date('2026-03-10T12:00:00Z')

/**
 * The page stamps times in the browser's own zone. Rebuilt here from the Date
 * API rather than from dayjs, so the expectation is not the implementation
 * read back, and so the suite holds in any TZ the machine happens to run in.
 */
const pad = (value) => String(value).padStart(2, '0')
const localStamp = (date, separator) =>
    `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}` +
    `${separator}${pad(date.getHours())}:${pad(date.getMinutes())}`

const passesSlot = { template: '<div><slot /></div>' }

const emptyHistory = { data: [], current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null }

beforeAll(() => {
    globalThis.route = (name, params) => (params === undefined ? `/${name}` : `/${name}/${params}`)
})

beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(NOW)
    hoisted.post.mockReset()
    hoisted.patch.mockReset()
    hoisted.visit.mockReset()
    hoisted.destroy.mockReset()
})

afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
})

const mountPage = async (props) => {
    const wrapper = mount(FastingIndex, {
        props: { activeFast: null, history: emptyHistory, ...props },
        global: {
            directives: { press: {} },
            stubs: { AuthenticatedLayout: passesSlot },
        },
    })

    await flushPromises()

    return wrapper
}

/** A fast started `seconds` ago, targeting `targetMinutes`. */
const runningFast = (seconds, targetMinutes) => ({
    id: 7,
    type: '16:8',
    start_time: new Date(NOW.getTime() - seconds * 1000).toISOString(),
    target_duration_minutes: targetMinutes,
})

const elapsedReadout = (wrapper) => wrapper.find('.font-mono.text-4xl').text()

describe('Fasting — le compteur du jeûne en cours', () => {
    it('affiche les heures, minutes et secondes écoulées', async () => {
        const wrapper = await mountPage({ activeFast: runningFast(2 * 3600 + 3 * 60 + 4, 16 * 60) })

        expect(elapsedReadout(wrapper)).toBe('02:03:04')
    })

    it('continue de compter seconde par seconde', async () => {
        const wrapper = await mountPage({ activeFast: runningFast(59, 16 * 60) })

        expect(elapsedReadout(wrapper)).toBe('00:00:59')

        vi.advanceTimersByTime(2000)
        await wrapper.vm.$nextTick()

        expect(elapsedReadout(wrapper)).toBe('00:01:01')
    })

    it('annonce le temps restant avant l’objectif', async () => {
        // 90 min done out of a 16 h target: 14 h 30 left.
        const wrapper = await mountPage({ activeFast: runningFast(90 * 60, 16 * 60) })

        expect(wrapper.text()).toContain('14:30:00 restants')
    })

    it('annonce l’objectif atteint plutôt qu’un décompte négatif', async () => {
        const wrapper = await mountPage({ activeFast: runningFast(17 * 3600, 16 * 60) })

        expect(wrapper.text()).toContain('Objectif atteint !')
        expect(wrapper.text()).not.toContain('restants')
    })

    it('remplit l’arc de progression à hauteur du temps écoulé', async () => {
        const wrapper = await mountPage({ activeFast: runningFast(4 * 3600, 16 * 60) })

        const circumference = 2 * Math.PI * 45
        const offset = Number(wrapper.findAll('circle')[1].attributes('stroke-dashoffset'))

        // A quarter of the way in: three quarters of the arc still empty.
        expect(offset).toBeCloseTo(circumference * 0.75, 5)
    })

    it('ne dépasse jamais l’arc complet quand l’objectif est explosé', async () => {
        const wrapper = await mountPage({ activeFast: runningFast(32 * 3600, 16 * 60) })

        expect(Number(wrapper.findAll('circle')[1].attributes('stroke-dashoffset'))).toBe(0)
    })

    it('termine le jeûne en horodatant la fin', async () => {
        const wrapper = await mountPage({ activeFast: runningFast(3600, 16 * 60) })

        const finish = wrapper.findAll('button').find((button) => button.text().includes('Terminer le jeûne'))
        await finish.trigger('click')

        expect(hoisted.patch).toHaveBeenCalledTimes(1)
        const [url, payload] = hoisted.patch.mock.calls[0]
        expect(url).toBe('/tools.fasting.update/7')
        expect(payload.end_time).toBe(`${localStamp(NOW, ' ')}:00`)
        expect(payload.status).toBe('completed')
    })

    it('arrête son horloge en quittant la page', async () => {
        const wrapper = await mountPage({ activeFast: runningFast(0, 16 * 60) })
        const clear = vi.spyOn(globalThis, 'clearInterval')

        wrapper.unmount()

        expect(clear).toHaveBeenCalled()
    })
})

describe('Fasting — démarrer un jeûne', () => {
    it('envoie le type et la durée du palier choisi', async () => {
        const wrapper = await mountPage({})

        await wrapper.find('select').setValue('36:0')
        await wrapper.find('form').trigger('submit')

        expect(hoisted.post).toHaveBeenCalledTimes(1)
        const [url, payload] = hoisted.post.mock.calls[0]
        expect(url).toBe('/tools.fasting.store')
        expect(payload.type).toBe('36:0')
        expect(payload.target_duration_minutes).toBe(36 * 60)
    })

    it('envoie le premier palier par défaut, sans y toucher', async () => {
        const wrapper = await mountPage({})

        await wrapper.find('form').trigger('submit')

        expect(hoisted.post.mock.calls[0][1]).toMatchObject({ type: '16:8', target_duration_minutes: 960 })
    })

    it('n’offre que des types que le serveur accepte', async () => {
        const wrapper = await mountPage({})

        // The empty one is GlassSelect's own placeholder, not a fasting type.
        const values = wrapper
            .findAll('select option')
            .map((option) => option.attributes('value'))
            .filter(Boolean)

        // The paliers on offer are a product decision; that every one of them is
        // a value StoreFastRequest will accept is not. The labels used to be the
        // source of the type — '24h (OMAD)' yielded '24h', which the rule below
        // rejects — so the rule is asserted, not only the list it happens to pass.
        const acceptedByStoreFastRequest = ['16:8', '18:6', '20:4', '24:0', '36:0', '48:0', 'custom']

        expect(values).toEqual(['16:8', '18:6', '20:4', '24:0', '36:0'])
        expect(values.filter((value) => !acceptedByStoreFastRequest.includes(value))).toEqual([])
    })

    it('préremplit l’heure de début avec l’instant présent', async () => {
        const wrapper = await mountPage({})

        expect(wrapper.find('input[type="datetime-local"]').element.value).toBe(localStamp(NOW, 'T'))
    })

    it('montre le refus du serveur, même sans champ associé', async () => {
        const wrapper = await mountPage({})

        wrapper.vm.startForm.errors = { base: 'Un jeûne est déjà en cours.' }
        await wrapper.vm.$nextTick()

        expect(wrapper.find('[dusk="fasting-error"]').text()).toBe('Un jeûne est déjà en cours.')
    })
})

describe('Fasting — historique', () => {
    const completed = {
        id: 1,
        type: '16:8',
        start_time: '2026-03-08T20:00:00Z',
        end_time: '2026-03-09T12:30:45Z',
    }

    const stillRunning = { id: 2, type: '20:4', start_time: '2026-03-09T20:00:00Z', end_time: null }

    const history = (data, extra = {}) => ({ ...emptyHistory, data, ...extra })

    it('donne la durée réelle de chaque jeûne terminé', async () => {
        const wrapper = await mountPage({ history: history([completed]) })

        expect(wrapper.text()).toContain('16:30:45')
    })

    it('dit « En cours » plutôt qu’une fin inventée', async () => {
        const wrapper = await mountPage({ history: history([stillRunning]) })

        expect(wrapper.text()).toContain('En cours')
        expect(wrapper.text()).not.toContain('Invalid Date')
    })

    it('n’envoie au graphique que ce qu’il y a, et affiche un mot quand il n’y a rien de terminé', async () => {
        const withChart = await mountPage({ history: history([completed]) })
        expect(withChart.findComponent({ name: 'FastingHistoryChart' }).props('data')).toEqual([completed])

        const withoutChart = await mountPage({ history: history([stillRunning]) })
        expect(withoutChart.findComponent({ name: 'FastingHistoryChart' }).exists()).toBe(false)
        expect(withoutChart.text()).toContain('Pas assez de données pour afficher le graphique.')
    })

    it('annonce un historique vide, et se tait dès qu’il y a quelque chose', async () => {
        expect((await mountPage({ history: history([]) })).text()).toContain('Aucun historique de jeûne.')

        // Without this half the assertion above holds for a message rendered
        // unconditionally, which is not what "annonce un historique vide" claims.
        expect((await mountPage({ history: history([completed]) })).text()).not.toContain('Aucun historique de jeûne.')
    })

    it('demande confirmation avant de supprimer', async () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
        const wrapper = await mountPage({ history: history([completed]) })

        await wrapper.find('[aria-label^="Supprimer le jeûne"]').trigger('click')
        expect(hoisted.destroy).not.toHaveBeenCalled()

        confirm.mockReturnValue(true)
        await wrapper.find('[aria-label^="Supprimer le jeûne"]').trigger('click')
        expect(hoisted.destroy).toHaveBeenCalledWith('/tools.fasting.destroy/1')
    })
})

describe('Fasting — pagination', () => {
    const paginated = (extra) => ({
        data: [{ id: 1, type: '16:8', start_time: '2026-03-08T20:00:00Z', end_time: '2026-03-09T12:00:00Z' }],
        current_page: 2,
        last_page: 3,
        prev_page_url: '/tools/fasting?page=1',
        next_page_url: '/tools/fasting?page=3',
        ...extra,
    })

    it('ne montre aucune navigation quand tout tient sur une page', async () => {
        const wrapper = await mountPage({ history: { ...paginated(), current_page: 1, last_page: 1 } })

        expect(wrapper.find('nav').exists()).toBe(false)
    })

    it('situe la page courante dans le total', async () => {
        const wrapper = await mountPage({ history: paginated() })

        expect(wrapper.find('nav').text()).toContain('Page 2 sur 3')
    })

    it('suit le lien de la page suivante', async () => {
        const wrapper = await mountPage({ history: paginated() })

        await wrapper.find('[dusk="fasting-next"]').trigger('click')

        expect(hoisted.visit).toHaveBeenCalledWith('/tools/fasting?page=3')
    })

    it('désactive le bouton qui ne mène nulle part', async () => {
        const wrapper = await mountPage({ history: paginated({ current_page: 3, next_page_url: null }) })

        expect(wrapper.find('[dusk="fasting-next"]').attributes('disabled')).toBeDefined()
        expect(wrapper.find('[dusk="fasting-prev"]').attributes('disabled')).toBeUndefined()
    })
})

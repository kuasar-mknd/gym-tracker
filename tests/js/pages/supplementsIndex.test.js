import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { nextTick } from 'vue'

/**
 * La question est posée par un dialogue de l'application, plus par `confirm()`.
 *
 * Ce n'était pas qu'une affaire d'apparence : sur mobile, plusieurs navigateurs
 * suppriment la boîte native après quelques appels, et le geste s'exécute alors
 * SANS question. Une confirmation qui peut disparaître n'en est pas une.
 */
const dialogue = (wrapper) => wrapper.findComponent({ name: 'ConfirmDialog' })

const confirmer = async (wrapper) => {
    await dialogue(wrapper).vm.$emit('confirmer')
}

/**
 * The supplements page is a stock cupboard. It decides on its own what counts
 * as running low, what can still be taken, and how long ago each product was
 * last used — none of that comes from a util — so the boundaries are asserted
 * here on the exact values a user hits, not on comfortable ones.
 */
const routerPost = vi.fn()
const routerDelete = vi.fn()

/** Both forms the page opens, in the order `useForm` is called for them. */
const forms = []
let form
let editForm

vi.mock('vue-chartjs', async () => (await import('../components/chartRecorder.js')).chartRecorders())

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await vi.importActual('vue')

    return {
        Head: { template: '<div />' },
        router: {
            post: (...args) => routerPost(...args),
            delete: (...args) => routerDelete(...args),
        },
        /*
         * Reactive rather than a plain literal: the add form's fields are bound
         * back into the template, so a frozen object would let an assertion
         * about a cleared form pass while the user still sees the old values.
         */
        useForm: (data) => {
            const created = reactive({
                ...data,
                processing: false,
                errors: {},
                post: vi.fn(),
                put: vi.fn(),
                reset: vi.fn(function reset() {
                    Object.assign(this, { ...data })
                }),
            })

            forms.push(created)

            return created
        },
    }
})

// The chart is a defineAsyncComponent behind a dynamic import; loaded first so
// the page's own import resolves from the module cache inside flushPromises.
await import('@/Components/Stats/SupplementUsageChart.vue')

const SupplementsIndex = (await import('@/Pages/Supplements/Index.vue')).default

const { passesSlot } = await import('./pageStubs')

beforeAll(() => {
    globalThis.route = (name, params) => `/${name}/${JSON.stringify(params ?? '')}`
})

beforeEach(() => {
    vi.clearAllMocks()
})

const supplement = (overrides = {}) => ({
    id: 1,
    name: 'Whey Protein',
    brand: 'MyProtein',
    dosage: '30 g / dose',
    servings_remaining: 20,
    low_stock_threshold: 5,
    last_taken_at: null,
    ...overrides,
})

/** Thirty days where nothing was taken: the chart card stays out of the way. */
const quietMonth = [
    { date: '2026-03-08', count: 0 },
    { date: '2026-03-09', count: 0 },
]

const mountPage = async (props = {}) => {
    forms.length = 0

    const wrapper = mount(SupplementsIndex, {
        // Deep copy: a shallow one hands every test the same nested arrays, and
        // a page that mutates a supplement then leaks it into the next test.
        props: structuredClone({ supplements: [supplement()], usageHistory: quietMonth, ...props }),
        global: {
            mocks: { route: globalThis.route },
            directives: { press: {} },
            stubs: { AuthenticatedLayout: passesSlot, GlassCard: passesSlot },
        },
    })

    await flushPromises()
    ;[form, editForm] = forms

    return wrapper
}

/** The page's buttons are GlassButtons; their copy is what the user aims at. */
const buttonsLabelled = (wrapper, label) =>
    wrapper.findAllComponents({ name: 'GlassButton' }).filter((button) => button.text().includes(label))

describe('reading the cupboard', () => {
    it('names a supplement bought loose “Générique” rather than leaving a hole', async () => {
        const wrapper = await mountPage({ supplements: [supplement({ brand: null })] })

        // The brand line is the card's subtitle; empty, the title floats and the
        // card no longer lines up with its neighbours in the grid.
        expect(wrapper.text()).toContain('Générique')
    })

    it('turns the stock red as soon as the alert threshold is reached, not only under it', async () => {
        const wrapper = await mountPage({
            supplements: [supplement({ servings_remaining: 5, low_stock_threshold: 5 })],
        })

        // Sitting exactly on the threshold already means "buy more"; warning
        // only below it means the warning arrives one dose too late.
        expect(wrapper.get('p.text-2xl').classes()).toContain('text-accent-danger-deep')
    })

    it('leaves the stock neutral while there is still margin', async () => {
        const wrapper = await mountPage({
            supplements: [supplement({ servings_remaining: 6, low_stock_threshold: 5 })],
        })

        expect(wrapper.get('p.text-2xl').classes()).not.toContain('text-accent-danger-deep')
    })
})

describe('the empty cupboard', () => {
    it('offers a way in when nothing is tracked yet', async () => {
        const wrapper = await mountPage({ supplements: [] })

        expect(wrapper.text()).toContain('Aucun complément')
    })

    it('steps aside the moment the add form is open', async () => {
        const wrapper = await mountPage({ supplements: [] })

        await wrapper.get('[aria-label="Ajouter un complément"]').trigger('click')

        // Both at once reads as "nothing to show" printed directly above a form
        // that is asking for the very first product.
        expect(wrapper.text()).not.toContain('Aucun complément')
        expect(wrapper.text()).toContain('Nouveau Complément')
    })

    it('opens the form from its own “Commencer” button', async () => {
        const wrapper = await mountPage({ supplements: [] })

        await buttonsLabelled(wrapper, 'Commencer')[0].trigger('click')

        // This is the button a first-time user reaches for; the header one is
        // small and sits away from where the eye already is.
        expect(wrapper.text()).toContain('Nouveau Complément')
    })
})

describe('the monthly usage chart', () => {
    it('stays hidden while not a single dose has been taken', async () => {
        const wrapper = await mountPage({ usageHistory: quietMonth })

        // Thirty flat bars say nothing: the card is pure noise until there is
        // something to read in it.
        expect(wrapper.text()).not.toContain('Consommation Mensuelle')
    })

    it('appears from the first dose of the period', async () => {
        const wrapper = await mountPage({
            usageHistory: [
                { date: '2026-03-08', count: 0 },
                { date: '2026-03-09', count: 2 },
            ],
        })

        expect(wrapper.text()).toContain('Consommation Mensuelle')
        expect(wrapper.findComponent({ name: 'SupplementUsageChart' }).props('data')).toHaveLength(2)
    })

    it('still renders the cupboard when the page is served with no history at all', async () => {
        const wrapper = await mountPage({ usageHistory: undefined })

        // Reading `.some` off an absent prop takes the whole page down with it,
        // supplements included — the part the user actually came for.
        expect(wrapper.text()).toContain('Whey Protein')
        expect(wrapper.text()).not.toContain('Consommation Mensuelle')
    })
})

describe('adding a supplement', () => {
    it('empties the form and closes it once the supplement is saved', async () => {
        const wrapper = await mountPage({ supplements: [] })

        await wrapper.get('[aria-label="Ajouter un complément"]').trigger('click')
        await wrapper.get('form input').setValue('Créatine')

        expect(form.name).toBe('Créatine')

        await wrapper.get('form').trigger('submit')

        const [, options] = form.post.mock.calls[0]

        options.onSuccess()
        await nextTick()

        // Left as it was, the next "Ajouter" opens on the previous product's
        // name and the user files a duplicate without noticing.
        expect(form.name).toBe('')
        expect(wrapper.text()).not.toContain('Nouveau Complément')
    })

    it('lets the form be dismissed without saving anything', async () => {
        const wrapper = await mountPage({ supplements: [] })

        await wrapper.get('[aria-label="Ajouter un complément"]').trigger('click')
        await buttonsLabelled(wrapper, 'Annuler')[0].trigger('click')

        // Opened by mistake and unable to close, the form sits over the list
        // until the page is reloaded.
        expect(wrapper.text()).not.toContain('Nouveau Complément')
        expect(form.post).not.toHaveBeenCalled()
    })
})

describe('editing a supplement', () => {
    it('opens the card on the supplement that was clicked, values already in place', async () => {
        const wrapper = await mountPage({
            supplements: [
                supplement(),
                supplement({
                    id: 42,
                    name: 'Créatine',
                    brand: 'Bulk',
                    dosage: '5 g',
                    servings_remaining: 120,
                    low_stock_threshold: 10,
                }),
            ],
        })

        await wrapper.findAll('[aria-label="Modifier le complément"]')[1].trigger('click')

        expect(wrapper.vm.editingSupplement).toBe(42)
        expect(editForm.name).toBe('Créatine')
        expect(editForm.brand).toBe('Bulk')
        expect(editForm.dosage).toBe('5 g')
        expect(editForm.servings_remaining).toBe(120)
        expect(editForm.low_stock_threshold).toBe(10)
    })

    it('reads a missing brand or dosage as an empty field, not as “null”', async () => {
        const wrapper = await mountPage({ supplements: [supplement({ brand: null, dosage: null })] })

        await wrapper.get('[aria-label="Modifier le complément"]').trigger('click')

        // Without the fallback the field shows the word null, which the user has
        // to select and delete before being able to save.
        expect(editForm.brand).toBe('')
        expect(editForm.dosage).toBe('')
    })

    it('saves against the supplement that is open, then closes the card', async () => {
        const wrapper = await mountPage({ supplements: [supplement(), supplement({ id: 42, name: 'Créatine' })] })

        await wrapper.findAll('[aria-label="Modifier le complément"]')[1].trigger('click')
        await buttonsLabelled(wrapper, 'Sauvegarder')[0].trigger('click')

        const [url, options] = editForm.put.mock.calls[0]

        expect(url).toContain('42')

        options.onSuccess()
        await nextTick()

        // Still open after a save, the card shows inputs over data the server has
        // already accepted, and the next click saves it a second time.
        expect(wrapper.vm.editingSupplement).toBeNull()
    })

    it('drops the typed values when the card is cancelled', async () => {
        const wrapper = await mountPage()

        await wrapper.get('[aria-label="Modifier le complément"]').trigger('click')

        expect(editForm.name).toBe('Whey Protein')

        await buttonsLabelled(wrapper, 'Annuler')[0].trigger('click')

        expect(wrapper.vm.editingSupplement).toBeNull()
        // One form is shared by every card: an abandoned edit left in it is what
        // the next supplement gets saved with.
        expect(editForm.name).toBe('')
    })
})

describe('taking a dose', () => {
    it('keeps the page where it was instead of jumping back to the top', async () => {
        const wrapper = await mountPage({ supplements: [supplement({ id: 42 })] })

        await buttonsLabelled(wrapper, 'Prendre une dose')[0].trigger('click')

        const [url, payload, options] = routerPost.mock.calls[0]

        expect(url).toContain('42')
        expect(payload).toEqual({})
        // Ticking off a shelf of supplements one by one from a page that scrolls
        // back to the top on every dose is unusable on a phone.
        expect(options).toMatchObject({ preserveScroll: true })
    })

    it('spins only the button that was pressed, and stops when the server answers', async () => {
        const wrapper = await mountPage({ supplements: [supplement({ id: 1 }), supplement({ id: 2 })] })

        await buttonsLabelled(wrapper, 'Prendre une dose')[1].trigger('click')

        const [, , options] = routerPost.mock.calls[0]

        options.onStart()
        await nextTick()

        // A spinner on every card would read as "all of them are being logged".
        expect(buttonsLabelled(wrapper, 'Prendre une dose')[0].props('loading')).toBe(false)
        expect(buttonsLabelled(wrapper, 'Prendre une dose')[1].props('loading')).toBe(true)

        options.onFinish()
        await nextTick()

        expect(buttonsLabelled(wrapper, 'Prendre une dose')[1].props('loading')).toBe(false)
    })

    it('refuses a dose from an empty box', async () => {
        const wrapper = await mountPage({ supplements: [supplement({ servings_remaining: 0 })] })

        // Sent anyway, the server rejects it; the button is the only place the
        // user can be told before spending a tap on it.
        expect(buttonsLabelled(wrapper, 'Prendre une dose')[0].props('disabled')).toBe(true)
    })

    it('still allows the very last dose', async () => {
        const wrapper = await mountPage({ supplements: [supplement({ servings_remaining: 1 })] })

        expect(buttonsLabelled(wrapper, 'Prendre une dose')[0].props('disabled')).toBe(false)
    })
})

describe('deleting a supplement', () => {
    it('demande avant, et ne supprime que sur confirmation', async () => {
        const wrapper = await mountPage({ supplements: [supplement({ id: 42 })] })

        await wrapper.get('[aria-label="Supprimer le complément"]').trigger('click')

        expect(dialogue(wrapper).props('ouvert')).toBe(true)
        expect(routerDelete).not.toHaveBeenCalled()

        await confirmer(wrapper)

        expect(routerDelete).toHaveBeenCalledTimes(1)
        expect(routerDelete.mock.calls[0][0]).toContain('42')
    })

    it('nomme le complément qu’on s’apprête à perdre', async () => {
        // Ce que la boîte native ne pouvait pas faire : « Supprimer ce
        // complément ? » ne dit pas lequel.
        const wrapper = await mountPage({ supplements: [supplement({ id: 42, name: 'Créatine' })] })

        await wrapper.get('[aria-label="Supprimer le complément"]').trigger('click')

        expect(dialogue(wrapper).props('description')).toContain('Créatine')
    })

    it('laisse le complément tranquille quand on annule', async () => {
        const wrapper = await mountPage({ supplements: [supplement({ id: 42 })] })

        await wrapper.get('[aria-label="Supprimer le complément"]').trigger('click')
        await dialogue(wrapper).vm.$emit('annuler')

        expect(dialogue(wrapper).props('ouvert')).toBe(false)
        expect(routerDelete).not.toHaveBeenCalled()
    })
})

describe('when a supplement was last taken', () => {
    /** An ISO stamp `seconds` in the past, read against the real clock. */
    const secondsAgo = (seconds) => new Date(Date.now() - seconds * 1000).toISOString()

    it('says “Jamais” rather than a blank while a supplement is untouched', async () => {
        const wrapper = await mountPage({ supplements: [supplement({ last_taken_at: null })] })

        // An empty slot under "Dernière prise" reads as a loading state.
        expect(wrapper.text()).toContain('Jamais')
    })

    it('answers “À l’instant” for a dose just logged', async () => {
        const wrapper = await mountPage()

        // "Il y a 0 min" is what the user would otherwise read right after
        // tapping the button they are still looking at.
        expect(wrapper.vm.formatDate(secondsAgo(30))).toBe("À l'instant")
    })

    it('counts down in whole elapsed minutes, never rounding up to the next one', async () => {
        const wrapper = await mountPage()

        // Rounding turns 1 min 50 into "Il y a 2 min", a dose the user has not
        // yet reached the point of taking again.
        expect(wrapper.vm.formatDate(secondsAgo(110))).toBe('Il y a 1 min')
        expect(wrapper.vm.formatDate(secondsAgo(59 * 60))).toBe('Il y a 59 min')
    })

    it('switches to whole elapsed hours past the hour', async () => {
        const wrapper = await mountPage()

        expect(wrapper.vm.formatDate(secondsAgo(90 * 60))).toBe('Il y a 1 h')
        expect(wrapper.vm.formatDate(secondsAgo(20 * 3600))).toBe('Il y a 20 h')
    })

    it('gives a calendar date once the dose is older than a day', async () => {
        // Pinned, so the expected answer is a literal. Derived from the clock,
        // the day number is a single digit that the time on the end of the same
        // string happens to contain, and the assertion holds for nothing.
        vi.useFakeTimers({ toFake: ['Date'] })
        vi.setSystemTime(new Date('2026-03-10T12:00:00Z'))

        try {
            const wrapper = await mountPage()

            const shown = wrapper.vm.formatDate('2026-03-05T12:00:00Z')

            // "Il y a 120 h" is arithmetic, not an answer: past a day the useful
            // question is which day, and the user has to count back to get it.
            expect(shown).not.toMatch(/Il y a|instant/)
            expect(shown).toContain('5 mars')
        } finally {
            vi.useRealTimers()
        }
    })
})

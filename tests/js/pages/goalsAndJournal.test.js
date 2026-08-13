import { describe, it, expect, vi, beforeAll, afterAll, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { reactive } from 'vue'

/**
 * Every submission any form on the page performs, in order. Both pages build
 * their forms with useForm — Journal even builds a throwaway one per deletion —
 * so a single ledger is the only way to assert "nothing was sent yet".
 */
const submissions = []

/**
 * A stand-in for Inertia's useForm that is reactive and that really resets.
 *
 * Reactivity is not decoration: both pages hand this object to a child form and
 * mutate it from a handler, so a plain object would let a test pass while the
 * screen never changed. And `reset()` restoring the *initial* values is what the
 * "form comes back empty" guards actually measure.
 */
const createForm = (data) => {
    const defaults = structuredClone(data)

    const form = reactive({
        ...structuredClone(data),
        processing: false,
        errors: {},
        reset: () => Object.assign(form, structuredClone(defaults)),
        post: (url, options = {}) => submissions.push({ method: 'post', url, options }),
        delete: (url, options = {}) => submissions.push({ method: 'delete', url, options }),
        put: vi.fn(),
        patch: vi.fn(),
    })

    return form
}

/** Today, as the pages see it — moved by hand so a test can cross midnight. */
let today = '2026-03-10'
const currentToday = () => today

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { id: 1 } } } }),
    router: { visit: vi.fn(), post: vi.fn(), reload: vi.fn(), delete: vi.fn() },
    useForm: (data) => createForm(data),
}))

// Only the clock is faked. parseCalendarDate stays real, because reading a
// stored day back as the same day is part of what these pages have to do.
vi.mock('@/Utils/date', async (importOriginal) => ({
    ...(await importOriginal()),
    todayAsCalendarDate: () => currentToday(),
}))

import GoalsIndex from '@/Pages/Goals/Index.vue'
import GoalCard from '@/Components/Goals/GoalCard.vue'
import GoalForm from '@/Components/Goals/GoalForm.vue'
import JournalIndex from '@/Pages/Journal/Index.vue'
import JournalForm from '@/Components/Journal/JournalForm.vue'
import JournalList from '@/Components/Journal/JournalList.vue'

/**
 * Both pages print stored calendar days — a deadline, the month an entry belongs
 * to. Run behind UTC on purpose: that is where a day read as an instant shows up
 * as the day before, so the whole file is a place where that mistake is visible.
 */
const systemTimeZone = process.env.TZ

beforeAll(() => {
    process.env.TZ = 'America/Los_Angeles'

    globalThis.route = (name, params = {}) => {
        if (name === 'goals.store') return '/goals'
        if (name === 'goals.edit') return `/goals/${params.goal}/edit`
        if (name === 'goals.destroy') return `/goals/${params.goal}`
        if (name === 'daily-journals.store') return '/daily-journals'
        if (name === 'daily-journals.destroy') return `/daily-journals/${params.daily_journal}`

        return `/${name}`
    }
})

afterAll(() => {
    if (systemTimeZone === undefined) {
        delete process.env.TZ
    } else {
        process.env.TZ = systemTimeZone
    }
})

beforeEach(() => {
    submissions.length = 0
    today = '2026-03-10'
})

const posts = () => submissions.filter((s) => s.method === 'post')
const deletions = () => submissions.filter((s) => s.method === 'delete')

/** Replays what Inertia does when the server accepts the last submission. */
const succeed = () => submissions[submissions.length - 1].options.onSuccess()

const passesSlot = { template: '<div><slot /></div>' }

/** The two toggles live in named header slots; a default-slot-only layout hides them. */
const layoutStub = { template: '<div><slot name="header-actions" /><slot name="header" /><slot /></div>' }

/** Buttons have to be real buttons here, so a click travels the way a user's does. */
const buttonStub = { inheritAttrs: false, template: '<button v-bind="$attrs"><slot /></button>' }

/** Honours `show`, so "the dialog is up" is an assertion about the page, not about the stub. */
const modalStub = { props: ['show'], template: '<div v-if="show"><slot /></div>' }

describe('Goals/Index', () => {
    const goal = (attributes = {}) => ({
        id: 1,
        title: 'Développé couché 100kg',
        type: 'weight',
        unit: 'kg',
        start_value: 60,
        current_value: 80,
        target_value: 100,
        progress_pct: 50,
        deadline: null,
        completed_at: null,
        ...attributes,
    })

    /**
     * Shallow, except for GoalCard: the card *is* the goal's readout, and the
     * delete flow starts on it. Stubbing it would leave both untested.
     */
    const mountGoals = async (goals) => {
        const wrapper = mount(GoalsIndex, {
            props: { goals: structuredClone(goals), exercises: [], measurementTypes: [] },
            shallow: true,
            global: {
                directives: { press: {} },
                // Ziggy publishes route() as a global property; GoalCard calls it
                // from its template, where a globalThis binding is out of scope.
                mocks: { route: (...args) => globalThis.route(...args) },
                stubs: {
                    AuthenticatedLayout: layoutStub,
                    GlassCard: passesSlot,
                    GlassButton: buttonStub,
                    Modal: modalStub,
                    GoalCard: false,
                },
            },
        })

        await flushPromises()

        return wrapper
    }

    const cardFor = (wrapper, id) => wrapper.findAllComponents(GoalCard).find((card) => card.props('goal').id === id)

    const sectionTitled = (wrapper, heading) => {
        const section = wrapper
            .findAll('div.space-y-4')
            .find((node) => node.find('h3').exists() && node.find('h3').text().includes(heading))

        expect(section, `no section titled ${heading}`).toBeTruthy()

        return section
    }

    const titlesUnder = (wrapper, heading) =>
        sectionTitled(wrapper, heading)
            .findAllComponents(GoalCard)
            .map((card) => card.props('goal').title)

    /**
     * The count a section's own heading claims. Read off the whole page instead,
     * the two counters can be swapped and both numbers are still somewhere on
     * screen, so nothing fails.
     */
    const countIn = (wrapper, heading) =>
        sectionTitled(wrapper, heading)
            .find('h3')
            .text()
            .match(/\((\d+)\)/)?.[1]

    const toggle = (wrapper) => wrapper.find('[dusk="create-goal-btn-desktop"]')

    /**
     * A goal that is done keeps its card, but under "Accomplis" — mixing the two
     * lists is how a finished goal keeps nagging the user from the active board.
     */
    it("range chaque objectif selon qu'il est accompli ou toujours en cours", async () => {
        const wrapper = await mountGoals([
            goal({ id: 1, title: 'En cours A' }),
            goal({ id: 2, title: 'Fini', completed_at: '2026-03-01T10:00:00.000000Z' }),
            goal({ id: 3, title: 'En cours B' }),
        ])

        expect(titlesUnder(wrapper, 'En cours')).toEqual(['En cours A', 'En cours B'])
        expect(titlesUnder(wrapper, 'Accomplis')).toEqual(['Fini'])
        expect(countIn(wrapper, 'En cours')).toBe('2')
        expect(countIn(wrapper, 'Accomplis')).toBe('1')

        wrapper.unmount()
    })

    /**
     * The répartition chart is fed a hand-built table, not the raw goals: it has
     * to count per type, keep the French labels, and leave out the types the
     * user has no goal for — an empty slice would render as a dead legend entry.
     */
    it('compte les objectifs par type et écarte les types sans objectif', async () => {
        const wrapper = await mountGoals([
            goal({ id: 1, type: 'weight' }),
            goal({ id: 2, type: 'weight' }),
            goal({ id: 3, type: 'measurement' }),
            goal({ id: 4, type: 'frequency' }),
        ])

        expect(wrapper.vm.goalDistribution).toEqual([
            { label: 'Force', count: 2 },
            { label: 'Fréquence', count: 1 },
            { label: 'Mesure', count: 1 },
        ])

        wrapper.unmount()
    })

    /** A type the front-end does not know must not crash the count, nor invent a slice. */
    it('ignore un type inconnu et masque la répartition quand il ne reste rien à montrer', async () => {
        const wrapper = await mountGoals([goal({ id: 1, type: 'sorcellerie' })])

        expect(wrapper.vm.goalDistribution).toEqual([])
        expect(wrapper.text()).not.toContain('Répartition')

        wrapper.unmount()
    })

    /** The phone-only action and the desktop one drive the same panel, and both say so. */
    it('ouvre et referme le formulaire de création depuis les deux entrées', async () => {
        const wrapper = await mountGoals([])
        const mobile = wrapper.find('[dusk="create-goal-btn"]')

        expect(wrapper.find('[dusk="goal-create-form"]').exists()).toBe(false)
        expect(toggle(wrapper).text()).toBe('Nouvel Objectif')
        expect(mobile.attributes('aria-label')).toBe('Nouvel objectif')

        await toggle(wrapper).trigger('click')

        expect(wrapper.find('[dusk="goal-create-form"]').exists()).toBe(true)
        expect(toggle(wrapper).text()).toBe('Annuler')
        expect(mobile.attributes('aria-label')).toBe("Annuler la création d'objectif")

        await mobile.trigger('click')

        expect(wrapper.find('[dusk="goal-create-form"]').exists()).toBe(false)

        wrapper.unmount()
    })

    /** Telling someone to create a goal while they are filling that very form is noise. */
    it("retire l'invitation à créer un objectif pendant qu'on en crée un", async () => {
        const wrapper = await mountGoals([])

        expect(wrapper.text()).toContain('Aucun objectif actif pour le moment')

        await toggle(wrapper).trigger('click')

        expect(wrapper.text()).not.toContain('Aucun objectif actif pour le moment')

        wrapper.unmount()
    })

    /** The empty state belongs to the active list only — completed goals do not fill it. */
    it("garde l'invitation quand tous les objectifs sont accomplis", async () => {
        const wrapper = await mountGoals([goal({ completed_at: '2026-03-01T10:00:00.000000Z' })])

        expect(wrapper.text()).toContain('Aucun objectif actif pour le moment')

        wrapper.unmount()
    })

    /**
     * Once the goal is saved the panel has to close *and* forget what was typed,
     * or the next "Nouvel Objectif" reopens on the previous goal's values and
     * quietly creates a duplicate.
     */
    it('referme et vide le formulaire une fois la création acceptée', async () => {
        const wrapper = await mountGoals([])

        await toggle(wrapper).trigger('click')
        wrapper.vm.form.title = 'Squat 140kg'
        wrapper.findComponent(GoalForm).vm.$emit('submit')
        await flushPromises()

        expect(posts()).toHaveLength(1)
        expect(posts()[0].url).toBe('/goals')

        succeed()
        await flushPromises()

        expect(wrapper.find('[dusk="goal-create-form"]').exists()).toBe(false)
        expect(wrapper.vm.form.title).toBe('')
        expect(wrapper.vm.form.type).toBe('weight')

        wrapper.unmount()
    })

    /** Cancelling from inside the form is the same escape hatch as the header toggle. */
    it('referme le formulaire quand celui-ci demande à être annulé', async () => {
        const wrapper = await mountGoals([])

        await toggle(wrapper).trigger('click')
        wrapper.findComponent(GoalForm).vm.$emit('cancel')
        await flushPromises()

        expect(wrapper.find('[dusk="goal-create-form"]').exists()).toBe(false)
        expect(posts()).toHaveLength(0)

        wrapper.unmount()
    })

    /**
     * Deleting a goal throws away its whole history, so the click on a card only
     * opens a dialog — and that dialog names the goal, because two cards sit side
     * by side and the wrong one is one row away.
     */
    it('demande confirmation en nommant l’objectif, sans rien supprimer', async () => {
        const wrapper = await mountGoals([
            goal({ id: 7, title: 'Squat 140kg' }),
            goal({ id: 8, title: 'Tractions x12' }),
        ])

        expect(wrapper.text()).not.toContain('Supprimer cet objectif ?')

        await cardFor(wrapper, 7).find('[dusk="delete-goal-7"]').trigger('click')

        expect(wrapper.text()).toContain('Supprimer cet objectif ?')
        expect(wrapper.text()).toContain('« Squat 140kg »')
        expect(wrapper.text()).not.toContain('Tractions x12 »')
        expect(deletions()).toHaveLength(0)

        wrapper.unmount()
    })

    /** Confirming sends the deletion for the goal that was picked, and closes the dialog. */
    it("supprime l'objectif choisi une fois la confirmation cliquée", async () => {
        const wrapper = await mountGoals([goal({ id: 7 }), goal({ id: 8 })])

        await cardFor(wrapper, 8).find('[dusk="delete-goal-8"]').trigger('click')
        await wrapper.find('[dusk="confirm-delete-goal"]').trigger('click')

        expect(deletions()).toHaveLength(1)
        expect(deletions()[0].url).toBe('/goals/8')
        expect(deletions()[0].options.preserveScroll).toBe(true)

        succeed()
        await flushPromises()

        expect(wrapper.text()).not.toContain('Supprimer cet objectif ?')

        wrapper.unmount()
    })

    /** Backing out of the dialog has to leave the goal alone. */
    it('renonce à la suppression quand le dialogue est fermé', async () => {
        const wrapper = await mountGoals([goal({ id: 7 })])

        await cardFor(wrapper, 7).find('[dusk="delete-goal-7"]').trigger('click')
        wrapper.findComponent(modalStub).vm.$emit('close')
        await flushPromises()

        expect(wrapper.text()).not.toContain('Supprimer cet objectif ?')
        expect(deletions()).toHaveLength(0)

        wrapper.unmount()
    })

    /**
     * The dialog can be dismissed while the confirm click is still in flight.
     * Without the guard the page would then delete `/goals/undefined` — a request
     * the user never asked for, on a goal that no longer exists in the dialog.
     */
    it('n’envoie rien si plus aucun objectif n’est en attente de suppression', async () => {
        const wrapper = await mountGoals([goal({ id: 7 })])

        wrapper.vm.deleteGoal()
        await flushPromises()

        expect(deletions()).toHaveLength(0)

        wrapper.unmount()
    })

    /** The icon and the wording are how a user tells a strength goal from a measurement one. */
    it('étiquette chaque carte selon le type de son objectif', async () => {
        const wrapper = await mountGoals([
            goal({ id: 1, type: 'weight' }),
            goal({ id: 2, type: 'frequency' }),
            goal({ id: 3, type: 'volume' }),
            goal({ id: 4, type: 'measurement' }),
            goal({ id: 5, type: 'sorcellerie' }),
        ])

        expect(cardFor(wrapper, 1).text()).toContain('🏋️‍♂️')
        expect(cardFor(wrapper, 1).text()).toContain('Poids (Max)')
        expect(cardFor(wrapper, 2).text()).toContain('📅')
        expect(cardFor(wrapper, 2).text()).toContain('Fréquence')
        expect(cardFor(wrapper, 3).text()).toContain('📊')
        expect(cardFor(wrapper, 3).text()).toContain('Volume')
        expect(cardFor(wrapper, 4).text()).toContain('📏')
        expect(cardFor(wrapper, 4).text()).toContain('Mesure')
        expect(cardFor(wrapper, 5).text()).toContain('🎯')
        expect(cardFor(wrapper, 5).text()).toContain('Objectif')

        wrapper.unmount()
    })

    /**
     * The percentage changes colour as the goal gets closer — it is the
     * at-a-glance signal. The bands open *above* 25 and 75, so those two values
     * are the ones asserted: anywhere in the middle of a band, tightening or
     * loosening a comparison changes nothing and the test never notices.
     */
    it("colore l'avancement selon la distance qui reste à parcourir", async () => {
        const wrapper = await mountGoals([
            goal({ id: 1, progress_pct: 25 }),
            goal({ id: 2, progress_pct: 75 }),
            goal({ id: 3, progress_pct: 76 }),
            goal({ id: 4, progress_pct: 100, completed_at: '2026-03-01T10:00:00.000000Z' }),
        ])

        /** The classes on the percentage readout itself, not just somewhere on the card. */
        const readout = (id) => cardFor(wrapper, id).get('.drop-shadow-sm').classes()

        expect(readout(1)).toContain('text-gray-500')
        expect(readout(2)).toContain('text-orange-500')
        expect(readout(3)).toContain('text-blue-500')
        // Completed wins over the percentage, which would otherwise read blue.
        expect(readout(4)).toContain('text-green-500')

        wrapper.unmount()
    })

    /**
     * A brand new goal comes back from the server without a computed percentage.
     * Read raw it renders "NaN%" and an unset progress bar; the fallback is what
     * keeps a fresh card readable.
     */
    it('affiche une progression nulle tant que le serveur n’en a pas calculé', async () => {
        const wrapper = await mountGoals([goal({ id: 1, progress_pct: null })])
        const card = cardFor(wrapper, 1)

        expect(card.text()).toContain('0%')
        expect(card.find('[role="progressbar"]').attributes('aria-valuenow')).toBe('0')
        expect(card.find('[role="progressbar"]').html()).toContain('width: 0%')

        wrapper.unmount()
    })

    /**
     * The deadline is a calendar day, not an instant: read as UTC it shows the day
     * before for everyone behind Greenwich. And a goal without a deadline must not
     * show an empty "Échéance :" line.
     */
    it("montre l'échéance telle qu'elle a été saisie, et rien sans échéance", async () => {
        const wrapper = await mountGoals([goal({ id: 1, deadline: '2026-03-01' }), goal({ id: 2, deadline: null })])

        expect(cardFor(wrapper, 1).text()).toContain('Échéance :')
        expect(cardFor(wrapper, 1).text()).toContain(new Date('2026-03-01T00:00:00').toLocaleDateString())
        expect(cardFor(wrapper, 2).text()).not.toContain('Échéance')

        wrapper.unmount()
    })

    /** The badge is the reward — it belongs to finished goals only. */
    it('décore d’un badge les seuls objectifs accomplis', async () => {
        const wrapper = await mountGoals([
            goal({ id: 1 }),
            goal({ id: 2, completed_at: '2026-03-01T10:00:00.000000Z' }),
        ])

        expect(cardFor(wrapper, 1).text()).not.toContain('COMPLÉTÉ')
        expect(cardFor(wrapper, 2).text()).toContain('COMPLÉTÉ')

        wrapper.unmount()
    })
})

describe('Journal/Index', () => {
    const journal = (attributes = {}) => ({
        id: 1,
        date: '2026-03-05',
        content: 'Bonne séance',
        mood_score: 4,
        sleep_quality: 3,
        stress_level: 2,
        energy_level: 5,
        motivation_level: 4,
        nutrition_score: 3,
        training_intensity: 5,
        ...attributes,
    })

    const mountJournal = async (journals) => {
        const wrapper = mount(JournalIndex, {
            props: { journals: structuredClone(journals) },
            shallow: true,
            global: {
                directives: { press: {} },
                stubs: {
                    AuthenticatedLayout: layoutStub,
                    GlassCard: passesSlot,
                    GlassButton: buttonStub,
                },
            },
        })

        await flushPromises()

        return wrapper
    }

    const openAdd = (wrapper) => wrapper.findAll('button').at(0).trigger('click')

    const groups = (wrapper) => wrapper.findComponent(JournalList).props('journalsByMonth')

    /**
     * History is read month by month. The key is built from the stored day — a
     * date read as an instant slides the 1st of a month into the previous one for
     * anyone behind UTC, and the entry disappears under the wrong heading.
     */
    it('regroupe les entrées sous le mois où elles ont été écrites', async () => {
        const wrapper = await mountJournal([
            journal({ id: 1, date: '2026-03-01' }),
            journal({ id: 2, date: '2026-03-15' }),
            journal({ id: 3, date: '2026-02-20' }),
        ])

        expect(Object.keys(groups(wrapper))).toEqual(['mars 2026', 'février 2026'])
        expect(groups(wrapper)['mars 2026'].map((j) => j.id)).toEqual([1, 2])
        expect(groups(wrapper)['février 2026'].map((j) => j.id)).toEqual([3])

        wrapper.unmount()
    })

    /**
     * The backend upserts on the date, so an entry opened with a stale day
     * overwrites that day instead of creating today's. The page can stay open
     * across midnight, which is exactly when a training journal gets written.
     */
    it('ouvre une entrée vierge datée du jour, même après le passage de minuit', async () => {
        const wrapper = await mountJournal([journal({ id: 9, content: 'Hier' })])

        wrapper.findComponent(JournalList).vm.$emit('edit', journal({ id: 9, content: 'Hier', date: '2026-03-09' }))
        await flushPromises()

        today = '2026-03-11'
        await openAdd(wrapper)

        expect(wrapper.vm.form.date).toBe('2026-03-11')
        expect(wrapper.vm.form.content).toBe('')
        expect(wrapper.vm.form.mood_score).toBeNull()
        expect(wrapper.findComponent(JournalForm).props('editingJournal')).toBeNull()

        wrapper.unmount()
    })

    /**
     * Editing has to bring back every score, not just the text: a metric left
     * behind is saved back as null and the day loses data the user had entered.
     */
    it("recharge l'entrée entière dans le formulaire quand on la modifie", async () => {
        const wrapper = await mountJournal([journal({ id: 9 })])
        const existing = journal({
            id: 9,
            date: '2026-02-14',
            content: 'Jambes',
            mood_score: 5,
            sleep_quality: 4,
            stress_level: 1,
            energy_level: 2,
            motivation_level: 3,
            nutrition_score: 4,
            training_intensity: 5,
        })

        wrapper.findComponent(JournalList).vm.$emit('edit', existing)
        await flushPromises()

        const form = wrapper.findComponent(JournalForm).props('form')

        expect(form.date).toBe('2026-02-14')
        expect(form.content).toBe('Jambes')
        expect(form.mood_score).toBe(5)
        expect(form.sleep_quality).toBe(4)
        expect(form.stress_level).toBe(1)
        expect(form.energy_level).toBe(2)
        expect(form.motivation_level).toBe(3)
        expect(form.nutrition_score).toBe(4)
        expect(form.training_intensity).toBe(5)
        expect(wrapper.findComponent(JournalForm).props('editingJournal')).toEqual(existing)

        wrapper.unmount()
    })

    /**
     * After a save the panel closes and forgets both the values and the entry it
     * was editing — otherwise the next "Ajouter" reopens in edit mode on an entry
     * the user has already left behind.
     */
    it("referme, vide et oublie l'entrée éditée une fois l'enregistrement accepté", async () => {
        const wrapper = await mountJournal([journal({ id: 9 })])

        wrapper.findComponent(JournalList).vm.$emit('edit', journal({ id: 9, content: 'Jambes' }))
        await flushPromises()

        wrapper.findComponent(JournalForm).vm.$emit('submit')
        await flushPromises()

        expect(posts()).toHaveLength(1)
        expect(posts()[0].url).toBe('/daily-journals')

        succeed()
        await flushPromises()

        expect(wrapper.findComponent(JournalForm).exists()).toBe(false)
        expect(wrapper.vm.form.content).toBe('')
        expect(wrapper.vm.editingJournal).toBeNull()

        wrapper.unmount()
    })

    /** Closing the form is a way out that must not save anything on the way. */
    it("referme le formulaire sans rien enregistrer quand l'utilisateur le ferme", async () => {
        const wrapper = await mountJournal([journal({ id: 9 })])

        await openAdd(wrapper)
        wrapper.findComponent(JournalForm).vm.$emit('close')
        await flushPromises()

        expect(wrapper.findComponent(JournalForm).exists()).toBe(false)
        expect(posts()).toHaveLength(0)

        wrapper.unmount()
    })

    /** A journal entry is not recoverable, so a refused confirmation must send nothing. */
    it('ne supprime une entrée que si la confirmation est acceptée', async () => {
        const wrapper = await mountJournal([journal({ id: 9 })])
        const list = wrapper.findComponent(JournalList)

        vi.spyOn(window, 'confirm').mockReturnValue(false)
        list.vm.$emit('delete', 9)
        await flushPromises()

        expect(deletions()).toHaveLength(0)

        window.confirm.mockReturnValue(true)
        list.vm.$emit('delete', 9)
        await flushPromises()

        expect(deletions()).toHaveLength(1)
        expect(deletions()[0].url).toBe('/daily-journals/9')

        window.confirm.mockRestore()
        wrapper.unmount()
    })

    /**
     * The "votre journal est vide" invitation is for an empty journal only — it
     * must not sit under the form the user just opened to fill it.
     */
    it("n'invite à commencer que si le journal est vide et le formulaire fermé", async () => {
        const wrapper = await mountJournal([])

        expect(wrapper.text()).toContain('Votre journal est vide')
        expect(wrapper.findComponent(JournalList).exists()).toBe(false)

        await openAdd(wrapper)

        expect(wrapper.text()).not.toContain('Votre journal est vide')
        expect(wrapper.findComponent(JournalForm).exists()).toBe(true)

        wrapper.unmount()
    })

    /** A trend needs two points; a chart drawn on a single entry says nothing. */
    it("n'affiche les tendances qu'à partir de deux entrées", async () => {
        const alone = await mountJournal([journal({ id: 1 })])

        expect(alone.text()).not.toContain('Tendances')
        alone.unmount()

        const several = await mountJournal([journal({ id: 1 }), journal({ id: 2, date: '2026-03-06' })])

        expect(several.text()).toContain('Tendances')
        several.unmount()
    })
})

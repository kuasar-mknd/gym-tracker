import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const visit = vi.fn()
const post = vi.fn()
const destroy = vi.fn()
const haptic = vi.fn()

/** La page d'Inertia : rendue par le serveur, ou servie depuis sa copie hors ligne (#2048). */
const pageDInertia = vi.hoisted(() => ({ props: {} }))

/**
 * Le démarrage d'une séance, commun aux boutons qui la démarrent (#2049), que
 * `seanceDemarreeSansReseauAvecInertia.test.js` joue avec le vrai client : ici,
 * que la liste l'appelle, d'où qu'on le demande.
 */
const demarrer = vi.fn()

vi.mock('@/composables/useDemarrageDUneSeance', async () => {
    const { ref } = await vi.importActual('vue')

    return { useDemarrageDUneSeance: () => ({ demarrer: (...args) => demarrer(...args), enCours: ref(false) }) }
})

vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: (...args) => haptic(...args) }))

/**
 * The `useForm({})` call on this page — the delete — has to come back as a
 * *reactive* object, and a plain object would freeze it at its initial value
 * and let every assertion about it pass for the wrong reason. Starting a
 * session no longer goes through a form (#2049).
 */
const forms = []

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await vi.importActual('vue')

    return {
        // Named, because a shallow mount keys its stubs off the component name
        // and an anonymous one gets stubbed away slots and all.
        Head: { name: 'Head', template: '<div />' },
        Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
        usePage: () => pageDInertia,
        router: { visit: (...args) => visit(...args), reload: vi.fn(), post: vi.fn() },
        useForm: (data) => {
            const form = reactive({
                ...data,
                processing: false,
                errors: {},
                post: (...args) => post(...args),
                delete: (...args) => destroy(...args),
                put: vi.fn(),
                reset: vi.fn(),
            })
            forms.push(form)

            return form
        },
    }
})

import WorkoutsIndex from '@/Pages/Workouts/Index.vue'
import CarteDeSeance from '@/Components/Workout/CarteDeSeance.vue'

/** La question passe par un dialogue de l'application, plus par `confirm()`. */
const dialogue = (wrapper) => wrapper.findComponent({ name: 'ConfirmDialog' })

beforeEach(() => {
    pageDInertia.props = { auth: { user: { id: 1 } } }
    demarrer.mockReset()
})

beforeAll(() => {
    globalThis.route = (name, params) => {
        if (name === 'workouts.store') return '/workouts'
        if (name === 'workouts.destroy') return `/workouts/${params.workout}`
        if (name === 'workouts.show') return `/workouts/${params.workout}`

        return `/${name}`
    }
})

const line = (id, name, setsCount) => ({ id, exercise: { id, name }, sets_count: setsCount })

/**
 * A first page of a three-page history: `total` and the number of rows on screen
 * deliberately disagree, because the page has to show the former.
 */
const paginator = {
    data: [
        {
            id: 1,
            name: 'Push Day',
            started_at: '2026-07-29T08:00:00.000000Z',
            workout_lines: [
                line(11, 'Développé', 4),
                line(12, 'Dips', 3),
                line(13, 'Écarté', 3),
                line(14, 'Pompes', 2),
                line(15, 'Pull-over', 2),
            ],
        },
        { id: 2, name: null, started_at: '2026-07-27T08:00:00.000000Z', workout_lines: [] },
        { id: 3, name: 'Leg Day', started_at: '2026-07-25T08:00:00.000000Z', workout_lines: [line(16, 'Squat', 5)] },
    ],
    total: 47,
    current_page: 1,
    last_page: 3,
    prev_page_url: null,
    next_page_url: '/workouts?page=2',
}

const passesSlot = { template: '<div><slot /></div>' }

/**
 * Shallow, so only the page's own markup renders — but the wrappers standing
 * between the page root and its rows have to pass their slots through, and the
 * layout has to pass its named ones too or the header, and both of its "new
 * session" buttons, never exist.
 */
const stubs = {
    AuthenticatedLayout: {
        template: '<div><slot name="header" /><slot name="header-actions" /><slot /></div>',
    },
    GlassCard: passesSlot,
    // A real <button>, so `:disabled` genuinely disables it rather than
    // rendering as decoration on a stub nobody can press.
    GlassButton: { template: '<button><slot /></button>' },
    SwipeableRow: { template: '<div><slot name="action-right" /><slot /></div>' },
    GlassEmptyState: { template: '<div dusk="empty-state" @click="$emit(\'action\')" />' },
    // Rendered by the @inertiajs/vue3 mock above rather than stubbed away: the
    // row link live inside its slots.
    Link: false,
    // Rendue pour de vrai : la rangée porte le bouton de suppression et le lien que les cas ci-dessous lisent.
    CarteDeSeance,
}

const mountPage = (overrides = {}) =>
    mount(WorkoutsIndex, {
        props: {
            workouts: structuredClone(paginator),
            ...overrides,
        },
        shallow: true,
        // The template calls route() directly, so it has to resolve through the
        // render context and not only through the module scope.
        global: { stubs, mocks: { route: globalThis.route } },
    })

/** The sessions on screen, in order, read back from their delete buttons. */
const rowIds = (wrapper) =>
    wrapper.findAll('[dusk^="delete-workout-"]').map((button) => Number(button.attributes('dusk').split('-').pop()))

const deleteRow = async (wrapper, id) => {
    const button = wrapper.find(`[dusk="delete-workout-${id}"]`)

    expect(button.exists(), `no delete button for session ${id}`).toBe(true)
    await button.trigger('click')
    await dialogue(wrapper).vm.$emit('confirmer')
    await wrapper.vm.$nextTick()
}

beforeEach(() => {
    visit.mockReset()
    post.mockReset()
    destroy.mockReset()
    haptic.mockReset()
    forms.length = 0
    globalThis.confirm = vi.fn(() => true)
})

/**
 * A row is the only thing identifying a past session, so what it says has to be
 * enough to recognise it by: a title even when the session was never named, a
 * date in the reader's language, and a hint of what was actually done.
 */
describe('Workouts/Index — what a history row says', () => {
    it('gives an unnamed session a title instead of an empty heading', () => {
        const wrapper = mountPage()
        const names = wrapper.findAll('h4').map((heading) => heading.text())

        expect(names).toEqual(['Push Day', 'Séance', 'Leg Day'])
    })

    it('writes the date in French, which is the only language this app speaks', () => {
        const wrapper = mountPage()

        expect(wrapper.text()).toContain('mer. 29 juil.')
    })

    /**
     * Five exercises will not fit on a phone-width row. Showing the first three
     * and counting the rest keeps the row readable without pretending the
     * session was shorter than it was.
     */
    it('previews three exercises and admits how many it left out', () => {
        const wrapper = mountPage()
        const text = wrapper.text()

        expect(text).toContain('Développé')
        expect(text).toContain('Dips')
        expect(text).toContain('Écarté')
        expect(text).not.toContain('Pompes')
        expect(text).not.toContain('Pull-over')
        expect(text).toContain('+2')
    })

    it('opens the session it names, not whichever one came first', () => {
        const wrapper = mountPage()
        const links = wrapper.findAll('a[href^="/workouts/"]').map((anchor) => anchor.attributes('href'))

        expect(links).toEqual(['/workouts/1', '/workouts/2', '/workouts/3'])
    })
})

/**
 * The history is paginated 20 to a page server-side. Without these controls
 * everything older than the twentieth session exists and cannot be reached.
 */
describe('Workouts/Index — reaching the older pages', () => {
    it('offers no pagination when the whole history already fits on one page', () => {
        const wrapper = mountPage({
            workouts: { ...structuredClone(paginator), last_page: 1, next_page_url: null },
        })

        expect(wrapper.find('[dusk="workouts-next"]').exists()).toBe(false)
    })

    /** Away from page 1, so a hardcoded "Page 1" cannot satisfy this. */
    it('offers pagination as soon as there is a second page, and says where you are', () => {
        const wrapper = mountPage({
            workouts: { ...structuredClone(paginator), current_page: 2, prev_page_url: '/workouts?page=1' },
        })

        expect(wrapper.find('[dusk="workouts-next"]').exists()).toBe(true)
        expect(wrapper.text()).toContain('Page 2 sur 3')
    })

    it('follows the paginator to the page it points at', async () => {
        const wrapper = mountPage()

        await wrapper.find('[dusk="workouts-next"]').trigger('click')

        expect(visit).toHaveBeenCalledWith('/workouts?page=2')
    })

    it('goes back to the newer sessions as well as forward', async () => {
        const wrapper = mountPage({
            workouts: { ...structuredClone(paginator), current_page: 2, prev_page_url: '/workouts?page=1' },
        })

        await wrapper.find('[dusk="workouts-prev"]').trigger('click')

        expect(visit).toHaveBeenCalledWith('/workouts?page=1')
    })

    it('greys out the direction that leads nowhere', () => {
        const wrapper = mountPage()

        expect(wrapper.find('[dusk="workouts-prev"]').attributes('disabled')).toBeDefined()
        expect(wrapper.find('[dusk="workouts-next"]').attributes('disabled')).toBeUndefined()
    })

    /**
     * The disabled button is an affordance, not a guarantee: Laravel sends
     * `null` for the edge pages, and a visit to `null` reloads the app at the
     * wrong URL. The guard is what actually stops it.
     */
    it('refuses to navigate when the paginator offers no such page', () => {
        const wrapper = mountPage()

        wrapper.vm.goToPage(null)

        expect(visit).not.toHaveBeenCalled()
    })
})

/**
 * Deleting is destructive and irreversible, and it is reached by swiping — which
 * happens by accident. Everything below is what stands between a stray swipe and
 * a lost session.
 */
describe('Workouts/Index — deleting a session', () => {
    it('asks first, and leaves everything alone when the answer is no', async () => {
        const wrapper = mountPage()

        await wrapper.find('[dusk="delete-workout-2"]').trigger('click')
        await dialogue(wrapper).vm.$emit('annuler')
        await wrapper.vm.$nextTick()

        expect(destroy).not.toHaveBeenCalled()
        expect(rowIds(wrapper)).toEqual([1, 2, 3])
    })

    it('names the session it is about to delete, even the unnamed one', async () => {
        const wrapper = mountPage()

        await wrapper.find('[dusk="delete-workout-1"]').trigger('click')
        expect(dialogue(wrapper).props('description')).toContain('Push Day')

        await dialogue(wrapper).vm.$emit('annuler')
        await wrapper.find('[dusk="delete-workout-2"]').trigger('click')

        // Une séance sans nom garde un repli lisible plutôt qu'un blanc.
        expect(dialogue(wrapper).props('description')).toContain('Séance')
    })

    it('ne supprime rien tant que la question n’est pas tranchée', async () => {
        const wrapper = mountPage()

        await wrapper.find('[dusk="delete-workout-3"]').trigger('click')

        expect(dialogue(wrapper).props('ouvert')).toBe(true)
        expect(destroy).not.toHaveBeenCalled()
    })

    it('sends the deletion for the session that was swiped', async () => {
        const wrapper = mountPage()

        await deleteRow(wrapper, 3)

        expect(destroy).toHaveBeenCalledWith('/workouts/3', expect.objectContaining({ preserveScroll: true }))
    })

    /** Waiting for the round trip before the row leaves makes the tap feel broken. */
    it('takes the row off screen without waiting for the server', async () => {
        const wrapper = mountPage()

        await deleteRow(wrapper, 2)

        expect(rowIds(wrapper)).toEqual([1, 3])
        expect(haptic).toHaveBeenCalledWith('warning')
    })

    /**
     * A refused deletion has to put the session back where it was: dropping it
     * at the end of the list would leave the history claiming a July session
     * happened after every other one.
     */
    it('puts a refused deletion back in its own place in the history', async () => {
        const wrapper = mountPage()

        await deleteRow(wrapper, 2)
        expect(rowIds(wrapper)).toEqual([1, 3])

        // Comme Inertia : le refus (sans rappel `onError`, rien), puis la fin de la visite.
        destroy.mock.calls[0][1].onError?.()
        destroy.mock.calls[0][1].onFinish()
        await wrapper.vm.$nextTick()

        expect(rowIds(wrapper)).toEqual([1, 2, 3])
        expect(haptic).toHaveBeenLastCalledWith('error')
    })

    /*
     * Sans réseau, sur un 403, un 419 ou un 500, sur une visite annulée,
     * Inertia n'appelle pas `onError` : la visite finit sans succès, et la
     * ligne revenait seulement au prochain chargement (#2077). Le vrai client
     * joue chaque cas dans `misesAJourOptimistesAvecInertia.test.js`.
     */
    it('puts the session back when the visit ends without succeeding, refusal or not', async () => {
        const wrapper = mountPage()

        await deleteRow(wrapper, 2)
        destroy.mock.calls[0][1].onFinish()
        await wrapper.vm.$nextTick()

        expect(rowIds(wrapper)).toEqual([1, 2, 3])
        expect(haptic).toHaveBeenLastCalledWith('error')
    })

    it('leaves the session gone once the server has deleted it', async () => {
        const wrapper = mountPage()

        await deleteRow(wrapper, 2)
        destroy.mock.calls[0][1].onSuccess()
        destroy.mock.calls[0][1].onFinish()
        await wrapper.vm.$nextTick()

        expect(rowIds(wrapper)).toEqual([1, 3])
        expect(haptic).not.toHaveBeenCalledWith('error')
    })

    /**
     * The page owns a copy of its server data. Splicing the prop instead would
     * work on screen and silently lose the change the moment Inertia replaces
     * the prop — and would corrupt the paginator the pagination controls read.
     */
    it('leaves the server data it was handed untouched', async () => {
        const wrapper = mountPage()

        await deleteRow(wrapper, 2)

        expect(wrapper.props('workouts').data.map((workout) => workout.id)).toEqual([1, 2, 3])
    })

    /**
     * A queued deletion can fire against a list the server has already changed
     * under it. `findIndex` answering -1 and going unchecked means `splice(-1)`,
     * which removes the last row — a session the user never touched.
     */
    it('deletes nothing when the session is no longer in the list', async () => {
        const wrapper = mountPage()

        wrapper.vm.confirmDeletion({ id: 999, name: 'Fantôme' })
        await wrapper.vm.$nextTick()

        expect(rowIds(wrapper)).toEqual([1, 2, 3])
        expect(destroy).not.toHaveBeenCalled()
    })
})

/**
 * Paginating, or any Inertia round trip, re-sends `workouts` with different
 * rows. A list built once at setup would keep showing page 1 for ever.
 */
describe('Workouts/Index — the server sends a different page', () => {
    it('shows the page the server just sent', async () => {
        const wrapper = mountPage()

        await wrapper.setProps({
            workouts: {
                ...structuredClone(paginator),
                data: [{ id: 9, name: 'Vieille séance', started_at: '2026-01-04T08:00:00.000000Z', workout_lines: [] }],
                current_page: 3,
                prev_page_url: '/workouts?page=2',
                next_page_url: null,
            },
        })

        expect(rowIds(wrapper)).toEqual([9])
        expect(wrapper.text()).toContain('Page 3 sur 3')
    })
})

/**
 * Creating a session is the whole point of the screen, and it is offered from
 * two places: the header, and the empty state someone arriving for the first
 * time will actually be looking at.
 */
describe('Workouts/Index — starting a session', () => {
    it('starts a session from the header button, through the common start', async () => {
        const wrapper = mountPage()

        await wrapper.findAll('[aria-label="Nouvelle séance"]')[0].trigger('click')

        expect(demarrer).toHaveBeenCalledTimes(1)
        expect(post).not.toHaveBeenCalled()
    })

    /*
     * Sans réseau, la liste disait qu'il fallait le réseau (#2048). La séance
     * démarre désormais par la file (#2049) : aucune visite ne part qui le dirait.
     */
    it('ne dit plus qu’il faut le réseau : la séance démarre sans lui, par la file', async () => {
        const wrapper = mountPage()

        await wrapper.findAll('[aria-label="Nouvelle séance"]')[0].trigger('click')

        expect(post).not.toHaveBeenCalled()
        expect(demarrer).toHaveBeenCalledTimes(1)
    })

    it('starts a session from the empty state as well', async () => {
        const wrapper = mountPage({ workouts: { ...structuredClone(paginator), data: [], total: 0 } })

        await wrapper.find('[dusk="empty-state"]').trigger('click')

        expect(demarrer).toHaveBeenCalledTimes(1)
    })
})

/**
 * "No sessions yet" and "sessions not loaded yet" look identical if the page
 * cannot tell them apart, and telling a returning user their history is empty
 * is worse than showing nothing.
 */
describe('Workouts/Index — an empty history', () => {
    it('invites a first session once the history is known to be empty', () => {
        const wrapper = mountPage({ workouts: { ...structuredClone(paginator), data: [], total: 0 } })

        expect(wrapper.find('[dusk="empty-state"]').exists()).toBe(true)
    })

    it('says nothing about emptiness while the history is still on its way', () => {
        const wrapper = mountPage({ workouts: undefined })

        expect(wrapper.find('[dusk="empty-state"]').exists()).toBe(false)
        expect(wrapper.find('[dusk="workouts-next"]').exists()).toBe(false)
    })
})

/*
 * Sur une copie hors ligne, rien ne part par une visite (#2048, partie E).
 * Démarrer une séance passe par la file depuis #2049, sans rien tirer de la
 * copie : « Nouvelle séance » et l'invitation d'un historique vide restent
 * ouvertes.
 */
describe('Workouts/Index — sur une copie hors ligne', () => {
    const marquer = () => {
        pageDInertia.props = {
            auth: { user: { id: 1 } },
            horsLigne: { copieDu: Date.now(), raison: 'reseau', datesDesProps: {} },
        }
    }

    it('démarre une séance depuis l’en-tête, sans raison de désactivation', async () => {
        marquer()
        const wrapper = mountPage()
        const bouton = wrapper.get('[aria-label="Nouvelle séance"]')

        expect(bouton.attributes('disabled')).toBeUndefined()
        expect(bouton.attributes('aria-describedby')).toBeUndefined()

        await bouton.trigger('click')

        expect(demarrer).toHaveBeenCalledTimes(1)
        expect(post).not.toHaveBeenCalled()
    })

    it('propose de démarrer depuis un historique vide', () => {
        marquer()
        const invitation = mountPage({ workouts: { ...structuredClone(paginator), data: [], total: 0 } }).get(
            '[dusk="empty-state"]',
        )

        expect(invitation.attributes('action-desactivee')).toBeUndefined()
        expect(invitation.attributes('raison-de-l-action')).toBeUndefined()
    })

    it('démarre depuis une page que le serveur a rendue', () => {
        const wrapper = mountPage()

        expect(wrapper.get('[aria-label="Nouvelle séance"]').attributes('disabled')).toBeUndefined()
        expect(wrapper.get('[aria-label="Nouvelle séance"]').attributes('aria-describedby')).toBeUndefined()
    })
})

/**
 * « Calendrier » et « Modèles », dans l'en-tête de bureau, se posaient dans un
 * `Link` autour d'un `GlassButton` : un bouton dans un lien, deux arrêts de
 * tabulation pour un seul geste (#2117). Le vrai `GlassButton` les rend ici.
 * Le segment mobile (`nav` « Séances »), jumeau de ces commandes sous 1 024 px,
 * est écarté de la recherche : il a ses propres cas plus bas.
 */
describe('Workouts/Index — les liens de l’en-tête de bureau', () => {
    it.each([
        ['Calendrier', 'calendar.index'],
        ['Modèles', 'templates.index'],
    ])('ouvre « %s » par un seul lien', (nom, nomDeRoute) => {
        const wrapper = mount(WorkoutsIndex, {
            props: {
                workouts: structuredClone(paginator),
            },
            shallow: true,
            global: {
                stubs: { ...stubs, GlassButton: false },
                directives: { press: {} },
                mocks: { route: globalThis.route },
            },
        })
        const commandes = wrapper
            .findAll('a[href], button:not([disabled])')
            .filter((commande) => commande.element.closest('nav[aria-label="Séances"]') === null)
            .filter((commande) => commande.text().replace(/\s+/g, ' ').trim() === nom)

        expect(wrapper.findAll('a button')).toHaveLength(0)
        expect(commandes.map((commande) => [commande.element.tagName, commande.attributes('href')])).toEqual([
            ['A', globalThis.route(nomDeRoute)],
        ])

        wrapper.unmount()
    })
})

describe('Workouts/Index — le segment mobile', () => {
    it('propose Historique, Modèles et Calendrier, chacun par un seul lien sauf la page courante', () => {
        const wrapper = mountPage()
        const segment = wrapper.get('nav[aria-label="Séances"]')

        expect(segment.text().replace(/\s+/g, ' ')).toBe('Historique Modèles Calendrier')
        expect(segment.get('[aria-current="page"]').text()).toBe('Historique')
        expect(segment.findAll('a').map((lien) => [lien.text(), lien.attributes('href')])).toEqual([
            ['Modèles', globalThis.route('templates.index')],
            ['Calendrier', globalThis.route('calendar.index')],
        ])
        expect(segment.findAll('button')).toHaveLength(0)
        expect(wrapper.text()).toContain('Démarrer une séance')
    })
})

/**
 * La page est le journal : une ligne d'action, le segment, puis la liste. Sans
 * séance, un compte neuf n'a qu'un bouton, celui de son état vide.
 */
describe('Workouts/Index — le journal', () => {
    const avecUneSeanceEnCours = (seanceEnCours, overrides = {}) =>
        mount(WorkoutsIndex, {
            props: { workouts: structuredClone(paginator), ...overrides },
            shallow: true,
            global: {
                stubs: {
                    ...stubs,
                    AuthenticatedLayout: {
                        template: `<div><slot name="header" /><slot :seance-en-cours='${JSON.stringify(seanceEnCours)}' /></div>`,
                    },
                },
                mocks: { route: globalThis.route },
            },
        })

    it('range la ligne d’action, le segment puis la liste, et rien d’autre', () => {
        const wrapper = mountPage()
        const html = wrapper.html()

        expect(html.indexOf('Démarrer une séance')).toBeGreaterThan(-1)
        expect(html.indexOf('Démarrer une séance')).toBeLessThan(html.indexOf('aria-label="Séances"'))
        expect(html.indexOf('aria-label="Séances"')).toBeLessThan(html.indexOf('Push Day'))
        expect(wrapper.text()).not.toContain('Exercices disponibles')
        expect(wrapper.text()).not.toContain('Aperçu historique')
        expect(wrapper.findAllComponents({ name: 'GlassStat' })).toHaveLength(0)
    })

    it('ne démarre qu’une fois sur un compte neuf : le bouton de l’état vide, ni la ligne d’action ni l’en-tête', () => {
        const wrapper = mountPage({ workouts: { ...structuredClone(paginator), data: [], total: 0 } })

        expect(wrapper.text()).not.toContain('Démarrer une séance')
        expect(wrapper.find('[aria-label="Nouvelle séance"]').exists()).toBe(false)
        expect(wrapper.findAll('[dusk="empty-state"]')).toHaveLength(1)
    })

    it('ne montre qu’une action « Reprendre » : celle du bandeau du layout, pas de bouton ni de « Démarrer » dans la page', () => {
        const wrapper = avecUneSeanceEnCours({ id: 2 })

        expect(wrapper.text()).not.toContain('Reprendre')
        expect(wrapper.text()).not.toContain('Démarrer une séance')
        expect(wrapper.find('[dusk="reprendre-la-seance"]').exists()).toBe(false)
    })

    it('ne propose pas « Démarrer » pour une séance en attente de la file : le bandeau l’ouvre', () => {
        const wrapper = avecUneSeanceEnCours({ id: 'attente', enAttenteDe: 'cle' })

        expect(wrapper.text()).not.toContain('Reprendre')
        expect(wrapper.text()).not.toContain('Démarrer une séance')
    })

    const pageDeux = () =>
        mountPage({
            workouts: {
                ...structuredClone(paginator),
                data: [{ id: 9, name: 'Dernière', started_at: '2026-06-01T08:00:00.000000Z', workout_lines: [] }],
                total: 21,
                current_page: 2,
                last_page: 2,
                prev_page_url: '/workouts?page=1',
                next_page_url: null,
            },
        })

    it('ne dit pas « Pas encore de séance » quand on supprime la dernière carte d’une page autre que la première, et revient à la page précédente une fois la suppression aboutie', async () => {
        const wrapper = pageDeux()

        await deleteRow(wrapper, 9)

        expect(rowIds(wrapper)).toEqual([])
        expect(wrapper.find('[dusk="empty-state"]').exists()).toBe(false)
        expect(wrapper.text()).not.toContain('Pas encore de séance')
        expect(wrapper.find('[dusk="workouts-page-vide"]').exists()).toBe(true)
        expect(visit).not.toHaveBeenCalled()

        destroy.mock.calls[0][1].onSuccess()

        expect(visit).toHaveBeenCalledWith('/workouts?page=1', { replace: true, preserveScroll: true })
    })

    it('ne quitte pas la page 2 tant que la suppression n’a pas abouti, et la liste revient si elle échoue', async () => {
        const wrapper = pageDeux()

        await deleteRow(wrapper, 9)
        destroy.mock.calls[0][1].onFinish()
        await wrapper.vm.$nextTick()

        expect(visit).not.toHaveBeenCalled()
        expect(rowIds(wrapper)).toEqual([9])
    })

    it('montre l’état vide, jamais une page sans carte, quand la seule séance de la première page est supprimée avant que le total ait suivi', async () => {
        const wrapper = mountPage({
            workouts: {
                ...structuredClone(paginator),
                data: [{ id: 9, name: 'Seule', started_at: '2026-06-01T08:00:00.000000Z', workout_lines: [] }],
                total: 1,
                last_page: 1,
                next_page_url: null,
            },
        })

        await deleteRow(wrapper, 9)

        expect(rowIds(wrapper)).toEqual([])
        expect(wrapper.findAll('[dusk="empty-state"]')).toHaveLength(1)
        expect(wrapper.text()).not.toContain('Démarrer une séance Démarrer')
        expect(wrapper.find('[aria-label="Nouvelle séance"]').exists()).toBe(false)
    })

    it('marque d’une pastille la seule séance ouverte de la liste', () => {
        const wrapper = avecUneSeanceEnCours({ id: 2 })
        const cartes = wrapper.findAllComponents(CarteDeSeance)

        expect(cartes.map((carte) => carte.props('enCours'))).toEqual([false, true, false])
        expect(wrapper.text().match(/En cours/g)).toHaveLength(1)
    })
})

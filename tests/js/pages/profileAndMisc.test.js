import { describe, it, expect, vi, beforeAll, beforeEach, afterAll } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

/**
 * Two of the four pages here print a date, and both of them are only correct
 * away from Greenwich: `Measurements/Parts/Show` pins local midnight precisely
 * so a bare 'Y-m-d' does not render as the previous day, and `Notifications`
 * formats in French. Run those assertions in UTC and both guards pass whatever
 * the page does, so the zone is pinned to one west of Greenwich — the offset is
 * the whole point.
 */
const originalTimeZone = process.env.TZ
process.env.TZ = 'America/New_York'
afterAll(() => {
    process.env.TZ = originalTimeZone
})

const formPost = vi.fn()
const formDelete = vi.fn()
const formReset = vi.fn()
const routerPost = vi.fn()
const routerVisit = vi.fn()

/**
 * `useForm` is mocked, but not into an inert bag of values: the pages read back
 * what they write to it (the unit box renders `form.unit`, a reset has to empty
 * the field on screen), so the stand-in is reactive and its `reset` restores the
 * initial values the way Inertia's does.
 */
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await vi.importActual('vue')

    return {
        Head: { template: '<div />' },
        Link: {
            props: { href: { type: String, default: '' }, method: { type: String, default: 'get' } },
            template: '<a :href="href" :data-method="method"><slot /></a>',
        },
        router: {
            post: (...args) => routerPost(...args),
            visit: (...args) => routerVisit(...args),
            reload: vi.fn(),
            delete: vi.fn(),
            patch: vi.fn(),
        },
        useForm: (data) => {
            const initial = { ...data }

            const form = reactive({
                ...data,
                errors: {},
                processing: false,
                post: (...args) => formPost(...args),
                delete: (...args) => formDelete(...args),
                reset: (...fields) => {
                    formReset(...fields)
                    const targets = fields.length > 0 ? fields : Object.keys(initial)
                    targets.forEach((field) => {
                        form[field] = initial[field]
                    })
                },
            })

            return form
        },
    }
})

/**
 * Chart.js behind a lazy import; the pages under test are not the chart.
 *
 * `__esModule` is what tells `defineAsyncComponent` it has been handed a module
 * and the component is on `default` — without it Vue mounts the namespace.
 */
vi.mock('@/Components/Stats/BodyPartHistoryChart.vue', () => ({
    __esModule: true,
    default: {
        name: 'BodyPartHistoryChart',
        props: ['data', 'label', 'unit'],
        template: '<div class="chart-stub" />',
    },
}))

import { passesSlot, layoutStub } from './pageStubs'

import PartShow from '@/Pages/Measurements/Parts/Show.vue'
import NotificationsIndex from '@/Pages/Notifications/Index.vue'
import ProfileIndex from '@/Pages/Profile/Index.vue'
import ToolsIndex from '@/Pages/Tools/Index.vue'

/**
 * The route names Laravel actually registers, read off the route files.
 *
 * `Profile/Index` and `Tools/Index` are little more than hard-coded lists of
 * links, so the only failure they can have is a link that goes nowhere — a
 * renamed or dropped route, or a resource action excluded on purpose (there is
 * no `goals.show`, and the route file says why). Counting the entries would
 * catch none of that and would break on every legitimate addition.
 */
const declaredRouteNames = (() => {
    const source = ['routes/web.php', 'routes/auth.php']
        .map((file) => readFileSync(resolve(__dirname, '../../..', file), 'utf8'))
        .join('\n')

    const resourceActions = ['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']
    const names = new Set()

    for (const [, name] of source.matchAll(/->name\('([^']+)'\)/g)) {
        names.add(name)
    }

    for (const [, resource, modifier, listed] of source.matchAll(
        /Route::resource\('([^']+)',[^;]*?(?:->(only|except)\(\[([^\]]*)\]\))?;/g,
    )) {
        const actions = [...(listed ?? '').matchAll(/'([^']+)'/g)].map(([, action]) => action)

        const registered =
            modifier === 'only'
                ? actions
                : modifier === 'except'
                  ? resourceActions.filter((action) => !actions.includes(action))
                  : resourceActions

        registered.forEach((action) => names.add(`${resource}.${action}`))
    }

    return names
})()

/** Every name the page asked Ziggy for while it rendered. */
let askedFor = []

/**
 * Params land in the query string on purpose: a URL built with the wrong
 * binding key reads `?id=2` instead of `?bodyPartMeasurement=2`, which is
 * exactly the mistake a positional stand-in would hide.
 */
const route = (name, params) => {
    askedFor.push(name)

    return params ? `/routes/${name}?${new URLSearchParams(params)}` : `/routes/${name}`
}

beforeAll(() => {
    globalThis.route = route
})

beforeEach(() => {
    askedFor = []
    formPost.mockReset()
    formDelete.mockReset()
    formReset.mockReset()
    routerPost.mockReset()
    routerVisit.mockReset()
})

/** Narrow spaces and non-breaking spaces vary by ICU build; the date does not. */
const normalise = (text) => text.replace(/[\u00a0\u202f\u2009]/g, ' ')

const clickText = async (wrapper, selector, label) => {
    const target = wrapper.findAll(selector).find((candidate) => candidate.text().includes(label))

    expect(target, `no ${selector} reading "${label}"`).toBeTruthy()
    await target.trigger('click')

    return target
}

// ---------------------------------------------------------------------------
// Measurements/Parts/Show
// ---------------------------------------------------------------------------

const historyFixture = [
    { id: 1, value: 38, unit: 'cm', measured_at: '2026-01-10', notes: null },
    { id: 2, value: 39.5, unit: 'cm', measured_at: '2026-03-15', notes: 'après le cycle' },
    { id: 3, value: 15.7, unit: 'in', measured_at: '2026-05-20', notes: null },
]

const mountPart = (history = historyFixture) => {
    const given = structuredClone(history)

    const wrapper = mount(PartShow, {
        props: { part: 'Biceps', history: given },
        global: {
            mocks: { route },
            directives: { press: {} },
            stubs: { AuthenticatedLayout: layoutStub, GlassCard: passesSlot },
        },
    })

    return { wrapper, given }
}

const openAddForm = async (wrapper) => {
    await clickText(wrapper, 'button', 'Add')

    const form = wrapper.find('form')

    expect(form.exists(), 'the add form did not open').toBe(true)

    return form
}

describe('Measurements/Parts/Show — the unit a new entry inherits', () => {
    /**
     * The unit is not editable on this form: whatever it is pre-filled with is
     * what gets saved. Taking it from the first entry rather than the last means
     * a part measured in centimetres years ago keeps overriding the inches it
     * has been measured in ever since.
     */
    it('takes the unit of the most recent measurement, not the oldest', async () => {
        const { wrapper } = mountPart()

        await openAddForm(wrapper)

        expect(wrapper.text()).toContain('15.7 in')
        expect(wrapper.find('form').element.parentElement.textContent).toContain('in')
        expect(wrapper.vm.form.unit).toBe('in')
    })

    it('falls back to centimetres on a part never measured', async () => {
        const { wrapper } = mountPart([])

        await openAddForm(wrapper)

        expect(wrapper.vm.form.unit).toBe('cm')
    })
})

describe('Measurements/Parts/Show — the history list', () => {
    it('puts the most recent measurement at the top', () => {
        const { wrapper } = mountPart()
        const text = wrapper.text()

        expect(text.indexOf('15.7 in')).toBeLessThan(text.indexOf('38 cm'))
    })

    /**
     * `history.reverse()` reverses in place. The chart is handed `history[0]`
     * for its unit and the whole array for its points, so a list that reversed
     * the prop rather than a copy of it silently re-labels the chart.
     */
    it('does not reverse the array the chart is reading', async () => {
        const { wrapper, given } = mountPart()

        // The chart is lazily imported, so it is on screen a tick after the page.
        await flushPromises()

        const chart = wrapper.findComponent({ name: 'BodyPartHistoryChart' })

        expect(given.map((entry) => entry.id)).toEqual([1, 2, 3])
        expect(chart.props('data').map((entry) => entry.id)).toEqual([1, 2, 3])
        expect(chart.props('unit')).toBe('cm')
    })

    it('leaves the chart out when there is nothing to plot', async () => {
        const { wrapper } = mountPart([])
        await flushPromises()

        expect(wrapper.findComponent({ name: 'BodyPartHistoryChart' }).exists()).toBe(false)
    })

    /**
     * A bare 'Y-m-d' handed to `new Date()` is read as UTC midnight, which west
     * of Greenwich is the previous evening — the entry saved on the 15th was
     * listed as the 14th.
     */
    it('dates an entry by the day it was recorded, not the day before', () => {
        const { wrapper } = mountPart([{ id: 9, value: 40, unit: 'cm', measured_at: '2026-03-15', notes: null }])
        const text = wrapper.text()

        expect(text).toMatch(/\b15\b/)
        expect(text).not.toMatch(/\b14\b/)
        expect(text).toContain('2026')
    })

    it('reads only the date out of a full timestamp', () => {
        const { wrapper } = mountPart([
            { id: 9, value: 40, unit: 'cm', measured_at: '2026-03-15T02:00:00.000000Z', notes: null },
        ])
        const text = wrapper.text()

        expect(text).toMatch(/\b15\b/)
        expect(text).not.toMatch(/\b14\b/)
    })

    /**
     * The delete control is an icon and nothing else, so without a name a screen
     * reader announces "button" on every row and none of them can be told apart.
     */
    it('names each delete button by the entry it removes', () => {
        const { wrapper } = mountPart()
        const labels = wrapper.findAll('[dusk^="delete-measurement-"]').map((button) => button.attributes('aria-label'))

        expect(labels).toHaveLength(3)
        labels.forEach((label) => expect(label).toMatch(/^Delete the .+ entry$/))
        expect(new Set(labels).size).toBe(3)
    })
})

describe('Measurements/Parts/Show — deleting an entry', () => {
    it('deletes the entry whose button was pressed, once confirmed', async () => {
        const confirm = vi.spyOn(globalThis, 'confirm').mockReturnValue(true)
        const { wrapper } = mountPart()

        await wrapper.find('[dusk="delete-measurement-2"]').trigger('click')

        expect(formDelete).toHaveBeenCalledWith('/routes/body-parts.destroy?bodyPartMeasurement=2')

        confirm.mockRestore()
    })

    it('deletes nothing when the confirmation is refused', async () => {
        const confirm = vi.spyOn(globalThis, 'confirm').mockReturnValue(false)
        const { wrapper } = mountPart()

        await wrapper.find('[dusk="delete-measurement-2"]').trigger('click')

        expect(formDelete).not.toHaveBeenCalled()

        confirm.mockRestore()
    })
})

describe('Measurements/Parts/Show — recording a measurement', () => {
    it('keeps the form shut until it is asked for', () => {
        const { wrapper } = mountPart()

        expect(wrapper.find('form').exists()).toBe(false)
    })

    it('posts to the body-parts endpoint', async () => {
        const { wrapper } = mountPart()

        const form = await openAddForm(wrapper)
        await form.trigger('submit')

        expect(formPost).toHaveBeenCalledWith('/routes/body-parts.store', expect.any(Object))
    })

    /**
     * Every field on this form writes straight onto the object that is posted,
     * so a field bound to the wrong one is not a display bug: it saves a
     * measurement the user never entered, and silently drops the one they did.
     */
    it('writes each field onto the entry that is posted', async () => {
        const { wrapper } = mountPart()

        await openAddForm(wrapper)

        const fieldWithLabel = (label) =>
            wrapper.findAllComponents({ name: 'GlassInput' }).find((input) => input.props('label') === label)

        await fieldWithLabel('Value').vm.$emit('update:modelValue', 42)
        await fieldWithLabel('Date').vm.$emit('update:modelValue', '2026-03-15')
        await fieldWithLabel('Notes (optional)').vm.$emit('update:modelValue', 'après le cycle')

        expect(wrapper.vm.form.value).toBe(42)
        expect(wrapper.vm.form.measured_at).toBe('2026-03-15')
        expect(wrapper.vm.form.notes).toBe('après le cycle')
        expect(wrapper.vm.form.part).toBe('Biceps')
    })

    /**
     * The date and the part are the two fields someone entering several
     * measurements in a row does not want to retype; a blanket `reset()` clears
     * them along with the value, and the part is not even editable on the form.
     */
    it('clears only the value and the notes once the entry is saved', async () => {
        const { wrapper } = mountPart()

        const form = await openAddForm(wrapper)
        wrapper.vm.form.value = 42
        wrapper.vm.form.notes = 'lourd'
        await form.trigger('submit')

        const [, options] = formPost.mock.calls.at(-1)
        options.onSuccess()
        await wrapper.vm.$nextTick()

        expect(formReset).toHaveBeenCalledWith('value', 'notes')
        expect(wrapper.vm.form.part).toBe('Biceps')
        expect(wrapper.vm.form.measured_at).toMatch(/^\d{4}-\d{2}-\d{2}$/)
    })

    it('closes the form once the entry is saved', async () => {
        const { wrapper } = mountPart()

        const form = await openAddForm(wrapper)
        await form.trigger('submit')

        formPost.mock.calls.at(-1)[1].onSuccess()
        await wrapper.vm.$nextTick()

        expect(wrapper.find('form').exists()).toBe(false)
    })
})

// ---------------------------------------------------------------------------
// Notifications/Index
// ---------------------------------------------------------------------------

const unreadNotification = {
    id: 'n-1',
    read_at: null,
    created_at: '2026-05-20T18:45:00.000000Z',
    data: { type: 'personal_record', title: 'Nouveau record', message: 'Développé couché 100 kg' },
}

const readNotification = {
    id: 'n-2',
    read_at: '2026-05-21T09:00:00.000000Z',
    created_at: '2026-05-19T07:05:00.000000Z',
    data: { type: 'reminder', title: 'Séance oubliée', message: 'Rien enregistré hier' },
}

const paginator = (overrides = {}) =>
    structuredClone({
        data: [unreadNotification, readNotification],
        current_page: 1,
        last_page: 1,
        prev_page_url: null,
        next_page_url: null,
        ...overrides,
    })

const mountNotifications = (notifications = paginator()) =>
    mount(NotificationsIndex, {
        props: { notifications },
        global: {
            mocks: { route },
            directives: { press: {} },
            stubs: { AuthenticatedLayout: layoutStub, GlassCard: passesSlot },
        },
    })

describe('Notifications/Index — marking as read', () => {
    it('marks the notification whose button was pressed, without moving the page', async () => {
        const wrapper = mountNotifications()

        await wrapper.findAll('[aria-label="Marquer comme lu"]')[0].trigger('click')

        expect(routerPost).toHaveBeenCalledWith(
            '/routes/notifications.mark-as-read?id=n-1',
            {},
            { preserveScroll: true },
        )
    })

    it('offers the button only on what is still unread', () => {
        const wrapper = mountNotifications()

        expect(wrapper.findAll('[aria-label="Marquer comme lu"]')).toHaveLength(1)
    })

    it('offers "tout marquer" while something is unread', async () => {
        const wrapper = mountNotifications()

        await clickText(wrapper, 'button', 'Tout marquer comme lu')

        expect(routerPost).toHaveBeenCalledWith('/routes/notifications.mark-all-as-read', {}, { preserveScroll: true })
    })

    it('drops "tout marquer" when nothing is unread', () => {
        const wrapper = mountNotifications(paginator({ data: [readNotification] }))

        expect(wrapper.text()).not.toContain('Tout marquer comme lu')
    })

    it('tells an unread notification apart from a read one', () => {
        const wrapper = mountNotifications()

        expect(wrapper.get('[data-notification-id="n-1"]').classes()).toContain('ring-1')
        expect(wrapper.get('[data-notification-id="n-2"]').classes()).toContain('opacity-70')
    })
})

describe('Notifications/Index — what a notification shows', () => {
    it('says nothing is waiting rather than showing an empty list', () => {
        const wrapper = mountNotifications(paginator({ data: [] }))

        expect(wrapper.text()).toContain('Aucune notification')
        expect(wrapper.findAll('[data-notification-id]')).toHaveLength(0)
    })

    it('drops the empty-state copy as soon as there is something to read', () => {
        const wrapper = mountNotifications()

        expect(wrapper.text()).not.toContain('Aucune notification')
        expect(wrapper.text()).toContain('Développé couché 100 kg')
    })

    /**
     * The date is written for a French reader and in their own zone: 18:45 UTC
     * is the evening in London and the early afternoon in New York, and the
     * notification has to be stamped with the hour the phone shows.
     */
    it('stamps a notification in French, at the local hour', () => {
        const wrapper = mountNotifications(paginator({ data: [unreadNotification] }))

        expect(normalise(wrapper.text())).toContain('20 mai, 14:45')
    })

    it('marks a record apart from the rest', () => {
        const wrapper = mountNotifications()

        // Le record porte le rose, l'ordinaire porte le cyan — et les deux
        // s'écrivent en aplat avec de l'encre. Le motif précédent posait la
        // couleur en TEXTE sur son propre lavis à 20 %, ce qui rendait 2,70:1
        // pour le rose et 1,36:1 pour le cyan : la distinction existait dans le
        // balisage sans être lisible à l'écran.
        const record = wrapper.get('[data-notification-id="n-1"]').html()
        const ordinaire = wrapper.get('[data-notification-id="n-2"]').html()

        expect(record).toContain('bg-accent-secondary')
        expect(ordinaire).toContain('bg-accent-info')

        // Ce que le test garde vraiment : que les deux DIFFÈRENT. Deux
        // assertions sur deux littéraux passeraient encore le jour où les deux
        // branches du ternaire porteraient la même couleur.
        expect(record).not.toContain('bg-accent-info')
        expect(ordinaire).not.toContain('bg-accent-secondary')
    })
})

describe('Notifications/Index — reaching past the first page', () => {
    it('shows no pagination when everything fits on one page', () => {
        const wrapper = mountNotifications()

        expect(wrapper.find('[aria-label="Pagination des notifications"]').exists()).toBe(false)
    })

    it('says which page of how many', () => {
        const wrapper = mountNotifications(paginator({ current_page: 2, last_page: 3 }))

        expect(wrapper.get('[aria-label="Pagination des notifications"]').text()).toContain('Page 2 sur 3')
    })

    it('follows the link the paginator gave it, in the direction pressed', async () => {
        const wrapper = mountNotifications(
            paginator({
                current_page: 2,
                last_page: 3,
                prev_page_url: '/notifications?page=1',
                next_page_url: '/notifications?page=3',
            }),
        )

        await wrapper.get('[dusk="notifications-next"]').trigger('click')

        expect(routerVisit).toHaveBeenLastCalledWith('/notifications?page=3')

        await wrapper.get('[dusk="notifications-prev"]').trigger('click')

        expect(routerVisit).toHaveBeenLastCalledWith('/notifications?page=1')
    })

    it('offers no way past the last page', async () => {
        const wrapper = mountNotifications(
            paginator({ current_page: 3, last_page: 3, prev_page_url: '/notifications?page=2' }),
        )

        expect(wrapper.get('[dusk="notifications-next"]').attributes('disabled')).toBeDefined()

        // The guard behind the disabled attribute: a null URL is not a visit to
        // `undefined`, it is no visit at all.
        wrapper.vm.goToPage(null)

        expect(routerVisit).not.toHaveBeenCalled()
    })
})

// ---------------------------------------------------------------------------
// Profile/Index
// ---------------------------------------------------------------------------

const mountProfile = (user = { name: 'samuel', email: 'sam@example.com' }) =>
    mount(ProfileIndex, {
        global: {
            mocks: { route, $page: { props: { auth: { user } } } },
            directives: { press: {} },
            stubs: { AuthenticatedLayout: layoutStub, GlassCard: passesSlot },
        },
    })

const menuItems = (wrapper) => wrapper.vm.menuGroups.flatMap((group) => group.items)

describe('Profile/Index — the menu', () => {
    it('sends every entry to a route the application declares', () => {
        const wrapper = mountProfile()

        const dead = menuItems(wrapper)
            .filter((item) => !declaredRouteNames.has(item.route))
            .map((item) => `${item.name} -> ${item.route}`)

        expect(dead, 'menu entries pointing at no route').toEqual([])
        expect(menuItems(wrapper).length).toBeGreaterThan(0)
    })

    it('gives every entry an icon and something to read', () => {
        const wrapper = mountProfile()

        const bare = menuItems(wrapper)
            .filter((item) => !item.icon?.trim() || !item.name?.trim() || !item.description?.trim())
            .map((item) => item.route)

        expect(bare, 'menu entries missing an icon, a name or a description').toEqual([])
    })

    it('renders every declared entry as a link that carries its icon', () => {
        const wrapper = mountProfile()

        menuItems(wrapper).forEach((item) => {
            const link = wrapper.find(`a[href="/routes/${item.route}"]`)

            expect(link.exists(), `${item.name} is declared but never rendered`).toBe(true)
            expect(link.text()).toContain(item.name)
            expect(link.get('.material-symbols-outlined').text()).toBe(item.icon)
        })
    })

    it('never sends two entries to the same place', () => {
        const wrapper = mountProfile()
        const routes = menuItems(wrapper).map((item) => item.route)

        expect(new Set(routes).size, `duplicated destinations: ${routes.join(', ')}`).toBe(routes.length)
    })

    /**
     * A GET on the logout route is a 405 at best, and a link a browser is free
     * to prefetch at worst.
     */
    it('logs out with a POST', () => {
        const wrapper = mountProfile()
        const logout = wrapper.get('[data-testid="logout-button"]')

        expect(declaredRouteNames.has('logout')).toBe(true)
        expect(logout.attributes('href')).toBe('/routes/logout')
        expect(logout.attributes('data-method')).toBe('post')
    })
})

describe('Profile/Index — the identity block', () => {
    it('builds the avatar from the initial of the name, in capitals', () => {
        const wrapper = mountProfile({ name: 'samuel', email: 'sam@example.com' })

        expect(wrapper.text()).toContain('samuel')
        expect(wrapper.text()).toContain('sam@example.com')
        expect(wrapper.get('.bg-gradient-main').text()).toBe('S')
    })

    it('survives an account with no name rather than blanking the page', () => {
        const wrapper = mountProfile({ name: null, email: 'sam@example.com' })

        expect(wrapper.get('.bg-gradient-main').text()).toBe('')
        expect(wrapper.text()).toContain('sam@example.com')
    })
})

// ---------------------------------------------------------------------------
// Tools/Index
// ---------------------------------------------------------------------------

const mountTools = () =>
    mount(ToolsIndex, {
        global: {
            mocks: { route },
            directives: { press: {} },
            stubs: { AuthenticatedLayout: layoutStub, GlassCard: passesSlot },
        },
    })

/** The tool cards, i.e. every link but the one going back to the profile. */
const toolCards = (wrapper) =>
    wrapper.findAll('a').filter((link) => link.attributes('href') !== '/routes/profile.index')

describe('Tools/Index — the tool grid', () => {
    it('sends every card to a route the application declares', () => {
        const wrapper = mountTools()

        const dead = askedFor.filter((name) => !declaredRouteNames.has(name))

        expect(dead, 'tools pointing at no route').toEqual([])
        expect(askedFor.length).toBeGreaterThan(0)
        expect(wrapper.findAll('a').length).toBe(askedFor.length)
    })

    it('gives every card an icon, a title and a line saying what it does', () => {
        const wrapper = mountTools()

        const bare = toolCards(wrapper)
            .filter((card) => {
                const hasIcon = card.find('svg').exists() || card.find('.material-symbols-outlined').exists()

                return !hasIcon || !card.find('h3').exists() || !card.find('p').text().trim()
            })
            .map((card) => card.attributes('href'))

        expect(bare, 'tool cards missing an icon, a title or a description').toEqual([])
        expect(toolCards(wrapper).length).toBeGreaterThan(0)
    })

    /**
     * These cards are copies of one another down to the class list, so the
     * mistake this grid invites is a new card left pointing at the tool it was
     * copied from.
     */
    it('never sends two cards to the same tool', () => {
        const wrapper = mountTools()
        const destinations = toolCards(wrapper).map((card) => card.attributes('href'))

        expect(new Set(destinations).size, `duplicated tools: ${destinations.join(', ')}`).toBe(destinations.length)
    })

    it('names every card so the link is not read out as its URL', () => {
        const wrapper = mountTools()

        toolCards(wrapper).forEach((card) => {
            expect(card.get('h3').text().trim(), `${card.attributes('href')} has no title`).not.toBe('')
        })
    })

    it('offers a named way back to the profile', () => {
        const wrapper = mountTools()
        const back = wrapper.get('[aria-label="Retour au profil"]')

        expect(back.attributes('href')).toBe('/routes/profile.index')
        expect(declaredRouteNames.has('profile.index')).toBe(true)
    })
})

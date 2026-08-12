import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { reactive } from 'vue'

const page = reactive({ props: {}, url: '/dashboard' })

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => page,
}))

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

/** Ziggy, including the wildcard patterns the desktop nav matches on. */
let currentRoute = 'dashboard'
const route = (name) => {
    if (name === undefined) {
        return {
            current: (pattern) => {
                if (pattern === undefined) return currentRoute
                if (!currentRoute) return false

                return new RegExp(`^${pattern.replace(/\./g, '\\.').replace(/\*/g, '.*')}$`).test(currentRoute)
            },
        }
    }

    return `/resolved/${name}`
}
globalThis.route = route

const stubs = {
    LiquidBackground: true,
    CelebrationModal: true,
    BottomNav: true,
    NavLink: { props: ['href', 'active'], template: '<a :href="href" :data-active="String(active)"><slot /></a>' },
    DropdownLink: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    Dropdown: { template: '<div><slot name="trigger" :open="false" /><slot name="content" /></div>' },
    ActiveWorkoutBanner: { name: 'ActiveWorkoutBanner', props: ['workout'], template: '<div />' },
}

const user = (overrides = {}) => ({ name: 'Samuel', unread_notifications_count: 0, ...overrides })

/**
 * Every layout mounted here watches the one shared `page`, so a layout left
 * mounted keeps reacting to the *next* test's flash and arming its own
 * dismissal timers. They are unmounted after each test.
 */
const mountedLayouts = []

const mountLayout = ({ props = {}, slots = {}, flash = {}, auth = { user: user() }, onPage = 'dashboard' } = {}) => {
    currentRoute = onPage
    page.props = { flash, auth }
    page.url = `/${onPage}`

    const wrapper = mount(AuthenticatedLayout, {
        props,
        slots: { default: '<p data-testid="page-content">contenu</p>', ...slots },
        global: { directives: { press: {} }, mocks: { route, $page: page }, stubs },
    })
    mountedLayouts.push(wrapper)

    return wrapper
}

/** Unmounts a layout mid-test, without the cleanup below unmounting it twice. */
const unmountLayout = (wrapper) => {
    mountedLayouts.splice(mountedLayouts.indexOf(wrapper), 1)
    wrapper.unmount()
}

const toasts = (wrapper) => wrapper.findAll('[role="alert"]')
const notificationLinks = (wrapper) => wrapper.findAll('a[href="/resolved/notifications.index"]')

/** The message of a toast, without the icon and close-button glyphs around it. */
const messageOf = (toast) => toast.get('p').text()

/**
 * Runs the clock forward in small steps and reports, for each message, the
 * moment its toast left the screen — or `null` if it never did. All the
 * messages are followed in a single pass, since the clock only moves forwards.
 *
 * @param {object} wrapper A mounted layout, under fake timers.
 * @param {string[]} messages The flashed messages to follow.
 * @returns {Promise<Object<string, ?number>>} Milliseconds from mount, per message.
 */
const dismissalTimes = async (wrapper, messages) => {
    const step = 250
    const giveUpAfter = 60000
    const times = Object.fromEntries(messages.map((message) => [message, null]))
    const stillUp = (message) => toasts(wrapper).some((toast) => messageOf(toast) === message)

    for (let now = 0; now <= giveUpAfter; now += step) {
        for (const message of messages) {
            if (times[message] === null && !stillUp(message)) {
                times[message] = now
            }
        }
        if (messages.every((message) => times[message] !== null)) break

        vi.advanceTimersByTime(step)
        await wrapper.vm.$nextTick()
    }

    return times
}

beforeEach(() => {
    currentRoute = 'dashboard'
})

afterEach(() => {
    mountedLayouts.splice(0).forEach((wrapper) => wrapper.unmount())
    vi.useRealTimers()
})

describe('AuthenticatedLayout — flash messages', () => {
    it('says nothing when the server flashed nothing', () => {
        expect(toasts(mountLayout())).toHaveLength(0)
    })

    it('shows the message the server flashed', () => {
        const wrapper = mountLayout({ flash: { success: 'Séance enregistrée' } })

        expect(toasts(wrapper)).toHaveLength(1)
        expect(toasts(wrapper)[0].text()).toContain('Séance enregistrée')
    })

    /**
     * A failure a screen reader is told about "politely" waits for the user to
     * stop what they are doing — which, for a save that did not happen, is too
     * late. Successes interrupt nobody.
     */
    it('interrupts for an error, and waits its turn for a success', () => {
        const wrapper = mountLayout({ flash: { success: 'Enregistré', error: 'Échec de la sauvegarde' } })

        const byMessage = Object.fromEntries(toasts(wrapper).map((t) => [messageOf(t), t]))

        expect(toasts(wrapper)).toHaveLength(2)
        expect(byMessage['Enregistré'].attributes('aria-live')).toBe('polite')
        expect(byMessage['Échec de la sauvegarde'].attributes('aria-live')).toBe('assertive')
    })

    it('lets the user dismiss it', async () => {
        const wrapper = mountLayout({ flash: { success: 'Séance enregistrée' } })

        await wrapper.get('[role="alert"] button[aria-label="Fermer"]').trigger('click')

        expect(toasts(wrapper)).toHaveLength(0)
    })

    it('takes the message away on its own', async () => {
        vi.useFakeTimers()
        const wrapper = mountLayout({ flash: { success: 'Séance enregistrée' } })

        expect(toasts(wrapper)).toHaveLength(1)

        vi.advanceTimersByTime(5000)
        await wrapper.vm.$nextTick()

        expect(toasts(wrapper)).toHaveLength(0)
    })

    /**
     * An error the user has to act on must outlast a confirmation they only
     * glance at — and must still leave on its own. The two deadlines are
     * compared to each other rather than to fixed millisecond figures: the
     * error's timer is re-armed every time the flash changes, so clearing the
     * success pushes the error's own deadline back, and the absolute number is
     * an accident of that. The order of the two is the promise to the user.
     */
    it('leaves an error up longer than a success', async () => {
        vi.useFakeTimers()
        const wrapper = mountLayout({ flash: { success: 'Enregistré', error: 'Échec' } })

        const dismissed = await dismissalTimes(wrapper, ['Enregistré', 'Échec'])

        expect(dismissed['Enregistré']).toBeGreaterThan(0)
        expect(dismissed['Échec']).not.toBeNull()
        expect(dismissed['Échec']).toBeGreaterThan(dismissed['Enregistré'])
    })

    /**
     * The dismissal timer outlived the page it belonged to: navigating away and
     * back within five seconds cleared the *new* page's flash.
     */
    it('drops its pending timer when the page goes away', () => {
        vi.useFakeTimers()
        const wrapper = mountLayout({ flash: { success: 'Séance enregistrée' } })

        unmountLayout(wrapper)
        vi.advanceTimersByTime(10000)

        expect(page.props.flash.success).toBe('Séance enregistrée')
    })
})

describe('AuthenticatedLayout — notifications', () => {
    it('counts the unread ones for a screen reader as well as for the eye', () => {
        const wrapper = mountLayout({
            props: { pageTitle: 'Accueil' },
            auth: { user: user({ unread_notifications_count: 3 }) },
        })

        expect(notificationLinks(wrapper)).toHaveLength(2)

        for (const link of notificationLinks(wrapper)) {
            expect(link.attributes('aria-label')).toBe('Notifications (3 non lues)')
            expect(link.text()).toContain('3')
        }
    })

    it('drops the badge, and the count in the label, when everything is read', () => {
        const wrapper = mountLayout({
            props: { pageTitle: 'Accueil' },
            auth: { user: user({ unread_notifications_count: 0 }) },
        })

        for (const link of notificationLinks(wrapper)) {
            expect(link.attributes('aria-label')).toBe('Notifications')
            expect(link.text()).not.toContain('0')
        }
    })
})

describe('AuthenticatedLayout — the mobile header', () => {
    it('stays away on a page that gave it neither a title nor a back link', () => {
        expect(mountLayout().find('header').exists()).toBe(false)
    })

    it('shows the page title it was given', () => {
        expect(
            mountLayout({ props: { pageTitle: 'Mesures' } })
                .get('h1')
                .text(),
        ).toBe('Mesures')
    })

    it('sends the back link to the route the page named', () => {
        const wrapper = mountLayout({ props: { showBack: true, backRoute: 'templates.index' } })

        expect(wrapper.get('[aria-label="Retour"]').attributes('href')).toBe('/resolved/templates.index')
    })

    it('falls back to the browser’s own history when no route was named', () => {
        const wrapper = mountLayout({ props: { showBack: true } })

        expect(wrapper.get('[aria-label="Retour"]').attributes('href')).toBe('javascript:history.back()')
    })

    it('makes room for the page’s own actions', () => {
        const wrapper = mountLayout({
            props: { pageTitle: 'Habitudes' },
            slots: { 'header-actions': '<button dusk="add-habit">+</button>' },
        })

        expect(wrapper.find('[dusk="add-habit"]').exists()).toBe(true)
    })

    it('leaves out the desktop heading band when the page supplies no header', () => {
        expect(mountLayout({ props: { pageTitle: 'Mesures' } }).findAll('header')).toHaveLength(1)
    })

    it('renders the desktop heading band when the page supplies one', () => {
        const wrapper = mountLayout({ props: { pageTitle: 'Mesures' }, slots: { header: '<h2>Mesures</h2>' } })

        expect(wrapper.findAll('header')).toHaveLength(2)
    })
})

describe('AuthenticatedLayout — the session in progress', () => {
    const active = { id: 7, name: 'Push A' }

    it('offers the way back into a session left running', () => {
        const wrapper = mountLayout({ auth: { user: user({ active_workout: active }) } })
        const banner = wrapper.findComponent({ name: 'ActiveWorkoutBanner' })

        expect(banner.exists()).toBe(true)
        expect(banner.props('workout')).toEqual(active)
    })

    /** On the session's own page the banner links to where the user already is. */
    it('stays quiet on the session’s own page', () => {
        const wrapper = mountLayout({ auth: { user: user({ active_workout: active }) }, onPage: 'workouts.show' })

        expect(wrapper.findComponent({ name: 'ActiveWorkoutBanner' }).exists()).toBe(false)
    })

    it('stays quiet when no session is running', () => {
        const wrapper = mountLayout()

        expect(wrapper.findComponent({ name: 'ActiveWorkoutBanner' }).exists()).toBe(false)
    })
})

describe('AuthenticatedLayout — the frame around the page', () => {
    /*
     * Looked for inside the main landmark, by a marker of its own, rather than
     * by its words anywhere on the frame: the skip link at the top already
     * reads "Aller au contenu principal", so a `toContain('contenu')` on the
     * whole layout passes with the default slot deleted outright.
     */
    it('renders the page inside it', () => {
        const wrapper = mountLayout()

        expect(wrapper.get('#main-content').find('[data-testid="page-content"]').exists()).toBe(true)
    })

    /** A skip link pointing at an id nothing carries skips to nowhere. */
    it('gives the skip link something to skip to', () => {
        const wrapper = mountLayout()
        const target = wrapper.get('a[href^="#"]').attributes('href').substring(1)

        expect(wrapper.find(`#${target}`).exists()).toBe(true)
    })

    it('lights the desktop tab of the section being viewed', () => {
        const wrapper = mountLayout({ onPage: 'workouts.show' })
        const byLabel = Object.fromEntries(wrapper.findAll('a[data-active]').map((a) => [a.text(), a]))

        expect(byLabel['Séances'].attributes('data-active')).toBe('true')
        expect(byLabel['Accueil'].attributes('data-active')).toBe('false')
    })
})

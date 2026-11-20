import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'

const routerPost = vi.fn()

/** Le démarrage commun (#2049) : ce qu'il fait se joue avec le vrai client d'Inertia ; ici, que le bouton l'appelle, et ce qu'il montre pendant. */
const demarrage = vi.hoisted(() => ({ demarrer: null, enCours: null }))

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: (...args) => routerPost(...args) },
}))
vi.mock('@/composables/useDemarrageDUneSeance', () => ({
    useDemarrageDUneSeance: () => ({ demarrer: demarrage.demarrer, enCours: demarrage.enCours }),
}))

import QuickActions from '@/Components/Dashboard/QuickActions.vue'

/*
 * Ce que le bouton central de la barre du bas prouvait en #2434, repris par le
 * bouton « Démarrer une séance » de l'Accueil, branché comme le fait
 * `Pages/Dashboard.vue` : `useDemarrageDUneSeance()` → `processing` et
 * `@start-workout`. Le bouton central est parti, il ne reste qu'un chemin vers
 * « démarrer » par page : ces cas suivent le geste, pas le bouton disparu.
 */
const Accueil = {
    components: { QuickActions },
    setup: () => ({ demarrer: demarrage.demarrer, enCours: demarrage.enCours }),
    template: '<QuickActions :processing="enCours" @start-workout="demarrer" />',
}

globalThis.route = (name) => `/resolved/${name}`

const monter = () => mount(Accueil, { global: { directives: { press: {} }, mocks: { route: globalThis.route } } })
const bouton = (wrapper) => wrapper.get('[dusk="start-workout-button"]')

beforeEach(() => {
    routerPost.mockReset()
    demarrage.enCours = ref(false)
    demarrage.demarrer = vi.fn(() => {
        demarrage.enCours.value = true
    })
})

describe('le bouton « Démarrer une séance » de l’Accueil', () => {
    it('démarre une séance par le démarrage commun, et pas par une visite d’Inertia', async () => {
        const wrapper = monter()
        const ouvert = vi.fn()
        window.addEventListener('open-add-exercise', ouvert)

        await bouton(wrapper).trigger('click')

        expect(demarrage.demarrer).toHaveBeenCalledTimes(1)
        expect(routerPost).not.toHaveBeenCalled()
        expect(ouvert).not.toHaveBeenCalled()

        window.removeEventListener('open-add-exercise', ouvert)
    })

    /*
     * Sans réseau, l'appui ne faisait rien, puis disait qu'il fallait le réseau
     * (#2048). La séance démarre désormais sans lui (#2049) : le bouton ne dit
     * plus rien lui-même, et aucune visite ne part qui le dirait.
     */
    it('ne dit plus qu’il faut le réseau : la séance démarre par la file', async () => {
        const wrapper = monter()

        await bouton(wrapper).trigger('click')

        expect(routerPost).not.toHaveBeenCalled()
        expect(wrapper.text()).not.toMatch(/réseau/i)
        expect(demarrage.demarrer).toHaveBeenCalledTimes(1)
    })

    it('ne crée pas deux séances quand on appuie deux fois : il se désactive tant que le démarrage dure', async () => {
        /*
         * Le verrou lui-même, qui tient pour deux boutons appelant chacun le
         * composable, se prouve avec le vrai composable (`useDemarrageDUneSeance.test.js`,
         * « le verrou de l’onglet ») ; ici, que le bouton se désactive et se dit occupé.
         */
        const wrapper = monter()

        await bouton(wrapper).trigger('click')
        await bouton(wrapper).trigger('click')

        expect(demarrage.demarrer).toHaveBeenCalledTimes(1)
        expect(bouton(wrapper).attributes('disabled')).toBeDefined()
        expect(bouton(wrapper).attributes('aria-busy')).toBe('true')
    })

    it('se rend quand le démarrage a fini, même en échec', async () => {
        const wrapper = monter()

        await bouton(wrapper).trigger('click')
        demarrage.enCours.value = false
        await wrapper.vm.$nextTick()

        expect(bouton(wrapper).attributes('disabled')).toBeUndefined()
    })

    /** Sur une copie hors ligne, rien ne part par une visite : le bouton reste ouvert, sans raison de désactivation. */
    it('reste ouvert et sans raison de désactivation, que la page vienne du serveur ou d’une copie hors ligne', async () => {
        const wrapper = monter()

        expect(bouton(wrapper).attributes('disabled')).toBeUndefined()
        expect(bouton(wrapper).attributes('aria-describedby')).toBeUndefined()

        await bouton(wrapper).trigger('click')

        expect(demarrage.demarrer).toHaveBeenCalledTimes(1)
        expect(routerPost).not.toHaveBeenCalled()
    })
})

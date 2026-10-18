import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { reactive } from 'vue'

/*
 * Sur une copie hors ligne, rien ne part vers le serveur, sauf la déconnexion
 * (#2048, partie E) : `visitesHorsLigne.js` arrête toute visite qui n'est pas
 * un GET. Les boutons qui en lanceraient une le disent donc d'avance,
 * désactivés, avec la raison que porte le bandeau de la copie
 * (`aria-describedby`). Ceux qui passent par la file hors ligne, ou ne
 * quittent pas l'appareil, restent ouverts.
 *
 * Ici, les composants qui n'ont pas déjà leur fichier de cas : l'accueil, la
 * séance, le minuteur de repos, l'état vide et l'interrupteur. Les pages et
 * les rangées ont les leurs (`workoutsIndex`, `exercisesIndex`, `intervalTimer`,
 * `plateCalculator`, `warmupCalculator`, `workoutShowUncovered`, `bottomNav`,
 * `recentActivity`, `carteDeSeance`, `sharedComponents`).
 */

/** La page d'Inertia : rendue par le serveur, ou servie depuis sa copie hors ligne. */
const etat = vi.hoisted(() => ({ page: null }))

vi.mock('@inertiajs/vue3', async () => {
    const { reactive: rendreReactive } = await vi.importActual('vue')
    etat.page = rendreReactive({ props: {} })

    return {
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
        usePage: () => etat.page,
    }
})

const { default: QuickActions } = await import('@/Components/Dashboard/QuickActions.vue')
const { default: ActionsDeLaSeance } = await import('@/Components/Workout/ActionsDeLaSeance.vue')
const { default: WorkoutSettingsModal } = await import('@/Components/Workout/WorkoutSettingsModal.vue')
const { default: RestTimer } = await import('@/Components/Workout/RestTimer.vue')
const { default: GlassEmptyState } = await import('@/Components/UI/GlassEmptyState.vue')
const { default: GlassToggle } = await import('@/Components/UI/GlassToggle.vue')
const { ID_DE_LA_RAISON_DES_ECRITURES } = await import('@/composables/useCopieHorsLigne')
const { noterQueLeServeurNeRepondPas, noterQueLeServeurRepond } = await import('@/composables/useEtatDuReseau')

const global = { directives: { press: {} }, mocks: { route: (nom) => `/${nom}` } }

const servirUneCopie = () => {
    etat.page.props = { horsLigne: { copieDu: Date.now(), raison: 'reseau', datesDesProps: {} } }
}

/** Le bouton est désactivé, et vise la raison du bandeau de la copie. */
const attendreDesactive = (bouton, raison = ID_DE_LA_RAISON_DES_ECRITURES) => {
    expect(bouton.attributes('disabled')).toBeDefined()
    expect(bouton.attributes('aria-describedby')).toBe(raison)
}

/** Le bouton part, sans raison. */
const attendreOuvert = (bouton) => {
    expect(bouton.attributes('disabled')).toBeUndefined()
    expect(bouton.attributes('aria-describedby')).toBeUndefined()
}

beforeEach(() => {
    etat.page.props = {}
    noterQueLeServeurRepond()
})

describe('l’accueil', () => {
    const demarrer = (wrapper) => wrapper.get('[dusk="start-workout-button"]')

    it('ne démarre pas de séance depuis une copie, et dit pourquoi', async () => {
        servirUneCopie()
        const wrapper = mount(QuickActions, { global })

        attendreDesactive(demarrer(wrapper))

        await demarrer(wrapper).trigger('click')

        expect(wrapper.emitted('startWorkout')).toBeUndefined()
    })

    it('en démarre une depuis une page que le serveur a rendue', async () => {
        const wrapper = mount(QuickActions, { global })

        attendreOuvert(demarrer(wrapper))

        await demarrer(wrapper).trigger('click')

        expect(wrapper.emitted('startWorkout')).toHaveLength(1)
    })
})

describe('les actions sous les exercices de la séance', () => {
    const monter = () => mount(ActionsDeLaSeance, { global })
    const bouton = (wrapper, dusk) => wrapper.get(`[dusk="${dusk}"]`)

    it('ni « Terminer » ni « Modèle » ne partent d’une copie, même le serveur revenu', async () => {
        servirUneCopie()
        const wrapper = monter()

        attendreDesactive(bouton(wrapper, 'finish-workout-mobile'))
        attendreDesactive(bouton(wrapper, 'enregistrer-comme-modele'))

        await bouton(wrapper, 'finish-workout-mobile').trigger('click')
        await bouton(wrapper, 'enregistrer-comme-modele').trigger('click')

        expect(wrapper.emitted('terminer')).toBeUndefined()
        expect(wrapper.emitted('enregistrer-comme-modele')).toBeUndefined()
    })

    it('ajouter un exercice et le repos restent : la file les garde, ou ils ne quittent pas l’appareil', async () => {
        servirUneCopie()
        const wrapper = monter()

        attendreOuvert(bouton(wrapper, 'add-exercise-existing'))
        attendreOuvert(bouton(wrapper, 'open-rest-timer'))

        await bouton(wrapper, 'add-exercise-existing').trigger('click')
        await bouton(wrapper, 'open-rest-timer').trigger('click')

        expect(wrapper.emitted('ajouter-un-exercice')).toHaveLength(1)
        expect(wrapper.emitted('demarrer-un-repos')).toHaveLength(1)
    })

    it('sans réseau, « Modèle » garde sa propre raison, que la page montre', () => {
        servirUneCopie()
        noterQueLeServeurNeRepondPas()
        const wrapper = monter()

        attendreDesactive(bouton(wrapper, 'enregistrer-comme-modele'), 'modele-sans-reseau')
        expect(wrapper.find('#modele-sans-reseau').exists()).toBe(true)
    })

    it('tout part d’une page que le serveur a rendue', () => {
        const wrapper = monter()

        attendreOuvert(bouton(wrapper, 'finish-workout-mobile'))
        attendreOuvert(bouton(wrapper, 'enregistrer-comme-modele'))
    })
})

describe('les réglages de la séance', () => {
    const monter = () =>
        mount(WorkoutSettingsModal, {
            props: {
                show: true,
                form: reactive({ name: 'Jambes', started_at: '', notes: '', errors: {}, processing: false }),
            },
            global: { ...global, stubs: { Modal: { template: '<div><slot /></div>' } } },
        })

    it('ne s’enregistrent pas sur une copie, même le serveur revenu, et disent pourquoi', async () => {
        servirUneCopie()
        const wrapper = monter()

        attendreDesactive(wrapper.get('[dusk="save-settings-button"]'))

        await wrapper.get('form').trigger('submit')

        expect(wrapper.emitted('submit')).toBeUndefined()
    })

    it('sans réseau, gardent leur propre raison', () => {
        servirUneCopie()
        noterQueLeServeurNeRepondPas()

        attendreDesactive(monter().get('[dusk="save-settings-button"]'), 'reglages-sans-reseau')
    })

    it('s’enregistrent depuis une page que le serveur a rendue', async () => {
        const wrapper = monter()

        attendreOuvert(wrapper.get('[dusk="save-settings-button"]'))

        await wrapper.get('form').trigger('submit')

        expect(wrapper.emitted('submit')).toHaveLength(1)
    })
})

describe('le minuteur de repos', () => {
    const monter = (props = {}) => mount(RestTimer, { props: { autoStart: false, ...props }, global })
    const interrupteur = (wrapper) => wrapper.get('[role="switch"]')

    it('ne change pas son démarrage automatique quand la page le désactive, et dit pourquoi', async () => {
        const wrapper = monter({ reglageDesactive: true, raisonDuReglage: ID_DE_LA_RAISON_DES_ECRITURES })

        attendreDesactive(interrupteur(wrapper))

        await interrupteur(wrapper).trigger('click')

        expect(wrapper.emitted('update:autoRestTimer')).toBeUndefined()
        wrapper.unmount()
    })

    it('le change sinon', async () => {
        const wrapper = monter()

        attendreOuvert(interrupteur(wrapper))

        await interrupteur(wrapper).trigger('click')

        expect(wrapper.emitted('update:autoRestTimer')).toEqual([[false]])
        wrapper.unmount()
    })
})

describe('les briques', () => {
    it('l’action d’un état vide se désactive, et vise sa raison', async () => {
        const wrapper = mount(GlassEmptyState, {
            props: { title: 'Rien', actionLabel: 'Créer', actionDesactivee: true, raisonDeLAction: 'pourquoi' },
            global,
        })
        const action = wrapper.get('[data-testid="empty-state-action"]')

        attendreDesactive(action, 'pourquoi')

        await action.trigger('click')

        expect(wrapper.emitted('action')).toBeUndefined()
    })

    it('l’action d’un état vide ne vise aucune raison tant qu’elle part', () => {
        const wrapper = mount(GlassEmptyState, {
            props: { title: 'Rien', actionLabel: 'Créer', raisonDeLAction: 'pourquoi' },
            global,
        })

        attendreOuvert(wrapper.get('[data-testid="empty-state-action"]'))
    })

    it('l’interrupteur porte la raison sur lui-même, que le lecteur d’écran annonce', () => {
        const wrapper = mount(GlassToggle, {
            props: { modelValue: true, label: 'Démarrage automatique', disabled: true, ariaDescribedby: 'pourquoi' },
            global,
        })

        attendreDesactive(wrapper.get('[role="switch"]'), 'pourquoi')
        expect(wrapper.attributes('aria-describedby')).toBeUndefined()
    })
})

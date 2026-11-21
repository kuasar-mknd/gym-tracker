import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest'

import { mount as monterVraiment, flushPromises } from '@vue/test-utils'

/*
 * Chaque page montée ici est démontée à la fin du test qui l'a montée.
 *
 * Ce fichier monte trois pages entières — Dashboard, Goals/Edit, Stats/Index —
 * et n'en démontait aucune. Leurs minuteurs et leurs promesses continuaient
 * donc de courir après la dernière assertion, et le journal qu'ils écrivaient
 * arrivait alors que le worker Vitest se fermait déjà :
 * `EnvironmentTeardownError: Closing rpc while "onUserConsoleLog" was pending`.
 * Les 1763 tests passent, la couverture tient son seuil, et la commande sort
 * quand même en erreur — ce qui a rendu `main` rouge et bloqué la publication
 * de l'image.
 *
 * `vitest.setup.js` documente déjà trois sources de ces journaux tardifs, tarie
 * chacune à son tour. Celle-ci est la quatrième, et c'est la seule qu'on ferme
 * par la racine plutôt qu'en faisant taire un émetteur : rien ne tourne plus
 * après la fin du test.
 *
 * Même remède que `authenticatedLayout.test.js`, qui tient déjà la liste de ce
 * qu'il a monté pour la même raison.
 */
const montees = []

const mount = (...args) => {
    const wrapper = monterVraiment(...args)
    montees.push(wrapper)

    return wrapper
}

afterEach(async () => {
    while (montees.length > 0) {
        montees.pop().unmount()
    }

    await flushPromises()
})
import { nextTick } from 'vue'

const formPost = vi.fn()
const formPut = vi.fn()
const routerGet = vi.fn()
const routerVisit = vi.fn()

/**
 * Three screens that had never been mounted. Two of them are mostly composition,
 * and composition is exactly where a test can go hollow: asserting that a child
 * is on the page says nothing, because the child is on the page whatever it was
 * handed. So what is checked here is the part the page itself decides — which
 * value reaches which child, which branch of a deferred load is showing, and
 * what a click actually sends to the server.
 *
 * The stand-ins are reactive: `Dashboard` hands the start in progress
 * (`useDemarrageDUneSeance`, #2049) to two different children, and a frozen
 * `false` would let a page that never wires it up at all look identical to one
 * that does.
 */
let deferredHasLanded = false

/** La page d'Inertia : rendue par le serveur, ou servie depuis sa copie hors ligne (#2048). */
const pageDInertia = vi.hoisted(() => ({ props: {} }))

/**
 * Le démarrage d'une séance, commun aux boutons qui la démarrent (#2049), que
 * `seanceDemarreeSansReseauAvecInertia.test.js` joue avec le vrai client : ici,
 * que l'accueil l'appelle, et ce qu'il montre pendant.
 */
const demarrage = vi.hoisted(() => ({ demarrer: null, enCours: null }))

vi.mock('@/composables/useDemarrageDUneSeance', () => ({
    useDemarrageDUneSeance: () => ({ demarrer: demarrage.demarrer, enCours: demarrage.enCours }),
}))

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await vi.importActual('vue')

    return {
        Head: { template: '<div />' },
        Link: {
            props: { href: { type: String, default: '' } },
            template: '<a :href="href"><slot /></a>',
        },
        // Inertia renders the fallback until the deferred prop lands, then swaps
        // in the default slot. Both halves matter and the page is never seen in
        // either of them otherwise, so the stand-in is switchable.
        Deferred: {
            setup: () => ({ hasLanded: () => deferredHasLanded }),
            template: '<div><slot v-if="hasLanded()" /><slot v-else name="fallback" /></div>',
        },
        router: {
            get: (...args) => routerGet(...args),
            visit: (...args) => routerVisit(...args),
        },
        usePage: () => pageDInertia,
        useForm: (data) => {
            const form = reactive({
                ...data,
                errors: {},
                processing: false,
                post: (...args) => {
                    form.processing = true

                    return formPost(...args)
                },
                put: (...args) => {
                    form.processing = true

                    return formPut(...args)
                },
                reset: () => {},
            })

            return form
        },
    }
})

import Dashboard from '@/Pages/Dashboard.vue'
import InvitationDInstallation from '@/Components/UI/InvitationDInstallation.vue'
import { installerLInvitationDInstallation } from '@/composables/useInvitationDInstallation'
import GoalsEdit from '@/Pages/Goals/Edit.vue'
import StatsIndex from '@/Pages/Stats/Index.vue'
import { layoutStub, passesSlot } from './pageStubs'
import { ref } from 'vue'

beforeAll(() => {
    globalThis.route = (name, params) => (params === undefined ? `/${name}` : `/${name}/${JSON.stringify(params)}`)
})

beforeEach(() => {
    vi.clearAllMocks()
    deferredHasLanded = false
    pageDInertia.props = {}
    demarrage.enCours = ref(false)
    demarrage.demarrer = vi.fn(() => {
        demarrage.enCours.value = true
    })
})

/**
 * A stub that keeps the props it was given, so the page's wiring is visible.
 *
 * @param {string} name - The component name to answer to.
 * @param {Array<string>} props - The props to record.
 * @returns {Object} A stub component.
 */
const recording = (name, props) => ({ name, props, template: '<div />' })

describe('Dashboard', () => {
    const quickActions = recording('QuickActions', ['processing'])
    const recentActivity = recording('RecentActivity', ['recentWorkouts', 'processing'])
    const goalsSummary = recording('GoalsSummary', ['activeGoals', 'indisponible'])

    const mountDashboard = (props = {}) =>
        mount(Dashboard, {
            props,
            global: {
                directives: { press: {} },
                mocks: { route: globalThis.route, $page: { props: { auth: { user: { name: 'Sam' } } } } },
                stubs: {
                    AuthenticatedLayout: layoutStub,
                    GlassCard: passesSlot,
                    QuickActions: quickActions,
                    RecentActivity: recentActivity,
                    GoalsSummary: goalsSummary,
                },
            },
        })

    it('démarre la séance par le démarrage commun, et non plus par la route de création', async () => {
        const wrapper = mountDashboard()

        wrapper.findComponent({ name: 'QuickActions' }).vm.$emit('startWorkout')

        expect(demarrage.demarrer).toHaveBeenCalledTimes(1)
        expect(formPost).not.toHaveBeenCalled()
    })

    /*
     * Sans réseau, l'accueil disait qu'il fallait le réseau (#2048). La séance
     * démarre désormais par la file (#2049) : aucune visite ne part qui le
     * dirait, et l'accueil ne dit rien lui-même.
     */
    it('ne dit plus qu’il faut le réseau : la séance démarre sans lui, par la file', () => {
        const wrapper = mountDashboard()

        wrapper.findComponent({ name: 'QuickActions' }).vm.$emit('startWorkout')

        expect(formPost).not.toHaveBeenCalled()
        expect(demarrage.demarrer).toHaveBeenCalledTimes(1)
    })

    it('accepte la demande de démarrage des deux endroits qui la proposent', () => {
        const wrapper = mountDashboard()

        wrapper.findComponent({ name: 'RecentActivity' }).vm.$emit('startWorkout')

        // The empty state inside RecentActivity offers the same button; wired to
        // nothing it is a button that visibly does not work.
        expect(demarrage.demarrer).toHaveBeenCalledTimes(1)
    })

    it('signale aux deux boutons que la séance est en train de démarrer', async () => {
        const wrapper = mountDashboard()
        const processingOf = (name) => wrapper.findComponent({ name }).props('processing')

        expect([processingOf('QuickActions'), processingOf('RecentActivity')]).toEqual([false, false])

        wrapper.findComponent({ name: 'QuickActions' }).vm.$emit('startWorkout')
        await nextTick()

        // Read while the start is still open: checked afterwards, a button
        // that was never told anything reads exactly like one that was.
        expect([processingOf('QuickActions'), processingOf('RecentActivity')]).toEqual([true, true])

        demarrage.enCours.value = false
        await nextTick()

        expect([processingOf('QuickActions'), processingOf('RecentActivity')]).toEqual([false, false])
    })

    it('montre des squelettes tant que les statistiques n’ont pas atterri', () => {
        const wrapper = mountDashboard()

        // Three placeholders, not one: the fallback stands in for two side-by-side
        // cards and the strip underneath them, so a single skeleton leaves the
        // page jumping when the real content arrives.
        expect(wrapper.findAllComponents({ name: 'GlassSkeleton' })).toHaveLength(3)
    })

    it('remplace les squelettes par les sections une fois les statistiques arrivées', () => {
        deferredHasLanded = true

        const wrapper = mountDashboard({
            analyticalStats: {
                weeklyVolume: { stats: { current_week_volume: 1200, percentage: 12 }, trend: [{ week: 'S1' }] },
                workoutDistributions: { duration: [{ range: '60-90', count: 3 }], time_of_day: [{ hour: 18 }] },
            },
        })

        // Each section fed from its own branch: reading them all out of the same
        // one leaves three populated cards showing the same thing.
        expect(wrapper.findAllComponents({ name: 'GlassSkeleton' })).toHaveLength(0)
        expect(wrapper.findComponent({ name: 'WeeklyVolumeSection' }).props('weeklyVolumeTrend')).toEqual([
            { week: 'S1' },
        ])
        expect(wrapper.findComponent({ name: 'DurationSection' }).props('durationDistribution')).toEqual([
            { range: '60-90', count: 3 },
        ])
        expect(wrapper.findComponent({ name: 'TimeOfDaySection' }).props('timeOfDayDistribution')).toEqual([
            { hour: 18 },
        ])
    })

    /*
     * Une copie hors ligne de l'accueil ne garde ni le dernier poids ni les
     * objectifs (#2048, `PROPS_JAMAIS_GARDEES`) : leurs cartes le disent,
     * plutôt que de disparaître comme pour un compte qui n'a rien noté.
     */
    it('sur une copie hors ligne, dit le dernier poids et les objectifs indisponibles', () => {
        pageDInertia.props = { horsLigne: { copieDu: Date.now(), raison: 'reseau', datesDesProps: {} }, errors: {} }
        const wrapper = mountDashboard()

        expect(wrapper.get('[dusk="dashboard-latest-weight-indisponible"]').text()).toContain(
            'Dernier poids indisponible hors ligne',
        )
        expect(wrapper.findComponent({ name: 'GoalsSummary' }).props('indisponible')).toBe(true)
    })

    // Sans la marque d'une copie, une prop absente ne dit rien d'un réseau absent.
    it('sur une page que le serveur a rendue, un compte qui n’a rien noté ne voit rien d’indisponible', () => {
        pageDInertia.props = { errors: {} }
        const wrapper = mountDashboard({ latestWeight: null, activeGoals: [] })

        expect(wrapper.find('[dusk="dashboard-latest-weight-indisponible"]').exists()).toBe(false)
        expect(wrapper.findComponent({ name: 'GoalsSummary' }).props('indisponible')).toBe(false)
    })

    /*
     * L'accueil n'a plus de graphique des objectifs (la progression se lit dans
     * le récapitulatif) ni de seconde courbe de durée : « Activité récente »
     * trace déjà la durée des mêmes séances (`RecentWorkoutsTimelineChart`).
     */
    it('ne dessine plus de graphique des objectifs ni de durée récente', () => {
        const wrapper = mountDashboard({ activeGoals: [{ id: 1, title: 'Squat 100', progress_pct: 40 }] })

        expect(wrapper.text()).not.toContain('Progression des objectifs')
        expect(wrapper.text()).not.toContain('Durée récente')
    })

    it('passe les objectifs au récapitulatif qui les résume', () => {
        const goals = [{ id: 1, title: 'Squat 100', progress_pct: 40 }]

        const wrapper = mountDashboard({ activeGoals: goals })

        expect(wrapper.findComponent({ name: 'GoalsSummary' }).props('activeGoals')).toEqual(goals)
    })

    it('passe les séances récentes à la liste d’activité', () => {
        const workouts = [{ id: 3, started_at: '2026-02-10T14:30:00Z' }]

        const wrapper = mountDashboard({ recentWorkouts: workouts })

        expect(wrapper.findComponent({ name: 'RecentActivity' }).props('recentWorkouts')).toEqual(workouts)
    })

    describe('les invitations de l’accueil', () => {
        /**
         * L'invitation d'installation, qui dit quand elle se montre. Elle se
         * montre ici dès son montage, comme sur iPhone.
         */
        const installationQuiSeMontre = {
            name: 'InvitationDInstallation',
            emits: ['visibilite'],
            mounted() {
                this.$emit('visibilite', true)
            },
            template: '<div />',
        }

        const monterLAccueil = ({
            flash = {},
            installation = { name: 'InvitationDInstallation', template: '<div />' },
        } = {}) =>
            mount(Dashboard, {
                global: {
                    directives: { press: {} },
                    mocks: {
                        route: globalThis.route,
                        $page: { props: { auth: { user: { name: 'Sam' } } }, flash },
                    },
                    stubs: {
                        AuthenticatedLayout: layoutStub,
                        GlassCard: passesSlot,
                        QuickActions: quickActions,
                        RecentActivity: recentActivity,
                        GoalsSummary: goalsSummary,
                        InvitationDInstallation: installation,
                        InvitationAuxNotifications: recording('InvitationAuxNotifications', [
                            'uneAutreInvitationPasseAvant',
                        ]),
                    },
                },
            })

        it('ne propose les notifications qu’au retour d’une fin de séance', () => {
            expect(monterLAccueil().findComponent({ name: 'InvitationAuxNotifications' }).exists()).toBe(false)

            const apresLaSeance = monterLAccueil({ flash: { proposerLesNotifications: true } })
            const invitation = apresLaSeance.findComponent({ name: 'InvitationAuxNotifications' })

            expect(invitation.exists()).toBe(true)
            expect(invitation.props('uneAutreInvitationPasseAvant')).toBe(false)
        })

        it('fait passer l’invitation d’installation avant celle des notifications', async () => {
            const wrapper = monterLAccueil({
                flash: { proposerLesNotifications: true },
                installation: installationQuiSeMontre,
            })
            await nextTick()

            expect(
                wrapper.findComponent({ name: 'InvitationAuxNotifications' }).props('uneAutreInvitationPasseAvant'),
            ).toBe(true)
        })

        /*
         * La vraie invitation d'installation, sur un évènement que le
         * navigateur a émis avant le retour à l'accueil, pendant la séance :
         * elle est là dès le montage, et le dit, sans quoi la carte des
         * notifications s'afficherait à côté (#2114).
         */
        it('fait passer l’invitation d’installation dès le montage, quand le navigateur a parlé sur une autre page', async () => {
            const nePlusEcouter = installerLInvitationDInstallation()

            try {
                const evenement = new Event('beforeinstallprompt', { cancelable: true })
                evenement.prompt = vi.fn()
                window.dispatchEvent(evenement)

                const wrapper = monterLAccueil({
                    flash: { proposerLesNotifications: true },
                    installation: InvitationDInstallation,
                })
                await nextTick()

                expect(wrapper.find('[dusk="invitation-installation"]').exists()).toBe(true)
                expect(
                    wrapper.findComponent({ name: 'InvitationAuxNotifications' }).props('uneAutreInvitationPasseAvant'),
                ).toBe(true)
            } finally {
                nePlusEcouter()
            }
        })
    })
})

describe('Stats/Index', () => {
    const cards = {
        MonthlyVolumeCard: recording('MonthlyVolumeCard', ['monthlyComparison']),
        VolumeTrendCard: recording('VolumeTrendCard', ['volumeTrend', 'currentPeriod']),
    }

    const mountStats = (props = {}) =>
        mount(StatsIndex, {
            props: { exercises: [], ...props },
            global: {
                directives: { press: {} },
                mocks: { route: globalThis.route },
                stubs: { AuthenticatedLayout: layoutStub, ...cards },
            },
        })

    const periodButton = (wrapper, label) => wrapper.findAll('button').find((button) => button.text() === label)

    const pressedPeriod = (wrapper) =>
        wrapper
            .findAll('button')
            .filter((button) => button.attributes('aria-pressed') === 'true')
            .map((button) => button.text())

    it('retient la période choisie sur le serveur', () => {
        expect(pressedPeriod(mountStats({ selectedPeriod: '90j' }))).toEqual(['3 MOIS'])
    })

    it('retombe sur trente jours quand le serveur n’a rien choisi', () => {
        // Not just "something is selected": with no default the whole strip
        // renders unpressed and the page silently shows an unlabelled period.
        expect(pressedPeriod(mountStats())).toEqual(['30 JOURS'])
    })

    it('recharge la page sur la période demandée sans la faire sauter', async () => {
        const wrapper = mountStats({ selectedPeriod: '30j' })

        await periodButton(wrapper, '1 AN').trigger('click')

        expect(routerVisit.mock.calls[0][0]).toBe('/stats.index')
        expect(routerVisit.mock.calls[0][1]).toMatchObject({
            data: { period: '1a' },
            preserveScroll: true,
            preserveState: true,
        })
    })

    it('déplace la sélection tout de suite, sans attendre le serveur', async () => {
        const wrapper = mountStats({ selectedPeriod: '30j' })

        await periodButton(wrapper, '7 JOURS').trigger('click')

        // preserveState keeps the component alive across the visit, so a page
        // that only read the prop would leave the old button pressed until the
        // response came back.
        expect(pressedPeriod(wrapper)).toEqual(['7 JOURS'])
        expect(wrapper.findComponent({ name: 'VolumeTrendCard' }).props('currentPeriod')).toBe('7j')
    })

    /*
     * Sans réseau, sur une réponse en erreur ou une visite annulée, la
     * sélection restait sur la période demandée, au-dessus des données de
     * l'ancienne (#2077). Le vrai client joue chaque cas dans
     * `misesAJourOptimistesAvecInertia.test.js`.
     */
    it('revient à la période de la page quand la visite finit sans succès', async () => {
        const wrapper = mountStats({ selectedPeriod: '30j' })

        await periodButton(wrapper, '7 JOURS').trigger('click')
        routerVisit.mock.calls[0][1].onFinish()
        await nextTick()

        expect(pressedPeriod(wrapper)).toEqual(['30 JOURS'])
    })

    it('garde la période demandée quand la visite réussit, même après une demande interrompue', async () => {
        const wrapper = mountStats({ selectedPeriod: '30j' })

        await periodButton(wrapper, '7 JOURS').trigger('click')
        await periodButton(wrapper, '1 AN').trigger('click')
        // La première, interrompue par la seconde, finit sans succès.
        routerVisit.mock.calls[0][1].onFinish()
        routerVisit.mock.calls[1][1].onSuccess()
        routerVisit.mock.calls[1][1].onFinish()
        await nextTick()

        expect(pressedPeriod(wrapper)).toEqual(['1 AN'])
    })

    /*
     * Sur une copie hors ligne (#2434, lot 4a), aucune carte n'a de quoi se
     * remplir, et les contrôles de la page demandent le réseau : `/stats?period=…`
     * n'est jamais gardée, la courbe d'un exercice est une requête. Une phrase,
     * et ce qui s'ouvre sans réseau.
     */
    describe('servie depuis une copie hors ligne', () => {
        beforeEach(() => {
            pageDInertia.props = { horsLigne: { copieDu: 1_700_000_000_000, raison: 'reseau' } }
        })

        it('dit une seule fois que les mesures reviennent avec le réseau, sans carte', () => {
            const wrapper = mountStats({ exercises: [{ id: 1, name: 'Squat' }], selectedPeriod: '30j' })

            expect(wrapper.findAll('[data-indisponible-hors-ligne]')).toHaveLength(1)
            expect(wrapper.get('[data-indisponible-hors-ligne]').text()).toContain(
                'Les mesures reviennent avec le réseau.',
            )
            expect(wrapper.findComponent({ name: 'MonthlyVolumeCard' }).exists()).toBe(false)
            expect(wrapper.findComponent({ name: 'VolumeTrendCard' }).exists()).toBe(false)
            expect(wrapper.findComponent({ name: 'SummaryStatsGrid' }).exists()).toBe(false)
        })

        it('ne propose ni période ni courbe d’exercice, qui mèneraient à « Hors ligne »', () => {
            const wrapper = mountStats({ exercises: [{ id: 1, name: 'Squat' }], selectedPeriod: '30j' })

            expect(wrapper.findAll('button').filter((bouton) => bouton.text().includes('JOURS'))).toEqual([])
            expect(wrapper.findComponent({ name: 'GlassSegmented' }).exists()).toBe(false)
            expect(wrapper.findComponent({ name: 'ExerciseProgressCard' }).exists()).toBe(false)
            expect(routerVisit).not.toHaveBeenCalled()
        })

        it('laisse la bibliothèque, qui s’ouvre sans réseau', () => {
            const wrapper = mountStats({ exercises: [], selectedPeriod: '30j' })

            expect(wrapper.get('a').attributes('href')).toBe('/exercises.index')
        })

        it('ne propose pas Corps, dont les mesures ne sont pas gardées', () => {
            const wrapper = mountStats({ exercises: [], selectedPeriod: '30j' })

            expect(wrapper.findAll('a').map((lien) => lien.attributes('href'))).not.toContain('/corps.index')
        })

        it('ne propose pas Records, qui demande le réseau', () => {
            const wrapper = mountStats({ exercises: [], selectedPeriod: '30j' })

            expect(wrapper.findAll('a').map((lien) => lien.attributes('href'))).not.toContain('/records.index')
            expect(wrapper.text()).not.toContain('Charge max')
        })

        it('remontre les cartes et le choix de période quand la page n’est plus une copie', () => {
            pageDInertia.props = {}
            const wrapper = mountStats({ selectedPeriod: '30j' })

            expect(wrapper.findAll('[data-indisponible-hors-ligne]')).toHaveLength(0)
            expect(wrapper.findComponent({ name: 'MonthlyVolumeCard' }).exists()).toBe(true)
            expect(pressedPeriod(wrapper)).toEqual(['30 JOURS'])
        })
    })

    it('mène à Corps, où le poids, la masse grasse et les mensurations ont été rangés', () => {
        const wrapper = mountStats()

        expect(wrapper.findAll('a').map((lien) => lien.attributes('href'))).toContain('/corps.index')
        expect(wrapper.text()).toContain('Poids, masse grasse, mensurations')
    })

    it('mène à Records, avant Corps', () => {
        const wrapper = mountStats()
        const liens = wrapper.findAll('a').map((lien) => lien.attributes('href'))

        expect(liens).toContain('/records.index')
        expect(liens.indexOf('/records.index')).toBeLessThan(liens.indexOf('/corps.index'))
        expect(wrapper.text()).toContain('Charge max, 1RM estimé, meilleur volume')
    })

    it('ne monte aucune carte de poids ni de masse grasse', () => {
        const wrapper = mountStats({ deferredData: { performance: {} } })

        expect(wrapper.findComponent({ name: 'WeightEvolutionCard' }).exists()).toBe(false)
        expect(wrapper.findComponent({ name: 'BodyFatCard' }).exists()).toBe(false)
    })

    it('survit à des données différées encore absentes', () => {
        const wrapper = mountStats()

        // Every card is fed through `deferredData?.…`; one missing guard and the
        // first render of the page throws before anything is drawn.
        expect(wrapper.findComponent({ name: 'MonthlyVolumeCard' }).props('monthlyComparison')).toBeUndefined()
        expect(wrapper.findComponent({ name: 'VolumeTrendCard' }).props('volumeTrend')).toBeUndefined()
    })

    it('va chercher chaque série dans la bonne branche des données différées', () => {
        const wrapper = mountStats({
            deferredData: {
                performance: {
                    monthlyComparison: { current_volume: 5000, percentage: 4 },
                    volumeTrend: [{ date: '2026-02-01', volume: 1000 }],
                },
            },
        })

        // Paired card by card: reading both series out of the same key would
        // hand one card the other's data and still look populated.
        expect(wrapper.findComponent({ name: 'MonthlyVolumeCard' }).props('monthlyComparison')).toEqual({
            current_volume: 5000,
            percentage: 4,
        })
        expect(wrapper.findComponent({ name: 'VolumeTrendCard' }).props('volumeTrend')).toEqual([
            { date: '2026-02-01', volume: 1000 },
        ])
    })
})

describe('Goals/Edit', () => {
    const goal = {
        id: 12,
        title: 'Squat 100 kg',
        type: 'exercise',
        target_value: 100,
        exercise_id: 4,
        measurement_type: null,
        deadline: '2026-12-31',
        start_value: 80,
    }

    const goalForm = recording('GoalForm', ['form', 'exercises', 'measurementTypes', 'submitLabel'])

    const exercises = [{ id: 4, name: 'Squat' }]
    const measurementTypes = [{ value: 'weight', label: 'Poids' }]

    const mountEdit = (overrides = {}) =>
        mount(GoalsEdit, {
            props: { goal: { ...goal, ...overrides }, exercises, measurementTypes },
            global: {
                mocks: { route: globalThis.route },
                stubs: { AuthenticatedLayout: layoutStub, GlassCard: passesSlot, GoalForm: goalForm },
            },
        })

    it('ouvre le formulaire sur l’objectif existant, tous champs compris', () => {
        const wrapper = mountEdit()

        // Field by field: seeding only the title would still show a form that
        // looks filled, and silently blank the target on the first save.
        expect(wrapper.findComponent({ name: 'GoalForm' }).props('form')).toMatchObject({
            title: 'Squat 100 kg',
            type: 'exercise',
            target_value: 100,
            exercise_id: 4,
            measurement_type: null,
            deadline: '2026-12-31',
            start_value: 80,
        })
    })

    it('renvoie tel quel le départ de 0 d’une mensuration : un renommage ne doit pas le dire vidé', () => {
        const wrapper = mountEdit({ type: 'measurement', measurement_type: 'weight', start_value: 0 })

        wrapper.findComponent({ name: 'GoalForm' }).vm.$emit('submit')

        // Vide, le serveur y lirait un départ effacé et reprendrait la dernière
        // mesure : un objectif ancien à départ 0, atteint, perdrait son atteinte.
        expect(formPut).toHaveBeenCalledTimes(1)
        expect(wrapper.findComponent({ name: 'GoalForm' }).props('form').start_value).toBe(0)
    })

    it('reprend la période de l’objectif existant', () => {
        const wrapper = mountEdit({ period: 'week' })

        expect(wrapper.findComponent({ name: 'GoalForm' }).props('form')).toMatchObject({ period: 'week' })
    })

    it('passe au formulaire les listes dans lesquelles il fait choisir', () => {
        const form = mountEdit().findComponent({ name: 'GoalForm' })

        // Not decoration: the form picks the exercise and the measurement out of
        // these two lists, and an empty one leaves a select the user cannot use
        // — on a page that otherwise looks perfectly filled in.
        expect(form.props('exercises')).toEqual(exercises)
        expect(form.props('measurementTypes')).toEqual(measurementTypes)
    })

    it('garde le libellé « Enregistrer » du formulaire, sans variante « Enregistrer les modifications »', () => {
        // La page de création passe « Créer » ; l'édition ne passe rien : GoalForm dit « Enregistrer » (#2355).
        expect(mountEdit().findComponent({ name: 'GoalForm' }).props('submitLabel')).toBeUndefined()
    })

    it('enregistre par PUT sur l’objectif édité, pas sur la collection', () => {
        const wrapper = mountEdit()

        wrapper.findComponent({ name: 'GoalForm' }).vm.$emit('submit')

        expect(formPut.mock.calls[0][0]).toBe('/goals.update/{"goal":12}')
    })

    it('renvoie à la liste quand on annule', () => {
        const wrapper = mountEdit()

        wrapper.findComponent({ name: 'GoalForm' }).vm.$emit('cancel')

        expect(routerGet.mock.calls[0][0]).toBe('/goals.index')
    })

    it('ne pose son lien de retour que sur bureau : le téléphone a la flèche de l’en-tête, et deux retours se doublent', () => {
        const classes = mountEdit()
            .findAll('a')
            .find((lien) => lien.text().includes('Retour aux objectifs'))
            .classes()

        expect(classes).toContain('hidden')
        expect(classes).toContain('bureau:inline-flex')
    })

    it('rappelle de quel objectif il s’agit', () => {
        expect(mountEdit({ title: 'Tractions x 10' }).text()).toContain('Tractions x 10')
    })
})

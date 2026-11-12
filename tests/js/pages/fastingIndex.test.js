import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import { layoutStub } from './pageStubs'
import { nomAccessible } from '../utils/nomAccessible'
import { poserLeFuseau } from '../utils/fuseauDeLAppareil'
import { CIBLE, cibleDuRendu } from '../conventions/ciblesTactiles'

/**
 * La question passe par un dialogue de l'application, plus par `confirm()`.
 *
 * Sur mobile, plusieurs navigateurs suppriment la boîte native après quelques
 * appels : le geste s'exécutait alors SANS question. Une confirmation qui peut
 * disparaître n'en est pas une.
 */
const dialogue = (wrapper) => wrapper.findComponent({ name: 'ConfirmDialog' })

const confirmer = async (wrapper) => {
    await dialogue(wrapper).vm.$emit('confirmer')
}

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
                // Les options de la visite (ses en-têtes) suivent le corps, pour qui les lit (#2198).
                post: (url, options) => hoisted.post(url, transformer(payload()), options),
                patch: (url, options) => hoisted.patch(url, transformer(payload()), options),
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
 * The page shows times in the browser's own zone, and sends the instant they
 * name (#2111). Le champ du début est une heure murale de l'appareil, à la
 * minute ; ce qui part au serveur porte son décalage, et désigne le même
 * instant où que soit l'appareil. Rebuilt here from the Date API rather than
 * from dayjs, so the expectation is not the implementation read back, and so
 * the suite holds in any TZ the machine happens to run in.
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

const boutonTerminer = (wrapper) =>
    wrapper.findAll('button').find((button) => button.text().includes('Terminer le jeûne'))

const dialogueDeFin = (wrapper) =>
    wrapper
        .findAllComponents({ name: 'ConfirmDialog' })
        .find((candidat) => candidat.props('titre') === 'Terminer le jeûne ?')

/** Le geste complet : l'appui sur le bouton, puis la réponse « Terminer » du dialogue. */
const terminerLeJeune = async (wrapper) => {
    await boutonTerminer(wrapper).trigger('click')
    await dialogueDeFin(wrapper).vm.$emit('confirmer')
}

/** Une heure qui porte son fuseau : `Z`, ou un décalage (`-04:00`, `+0100`). */
const AVEC_SON_DECALAGE = /(?:Z|[+-]\d{2}:?\d{2})$/

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

    it('termine le jeûne en horodatant la fin à l’instant, avec son décalage', async () => {
        const wrapper = await mountPage({ activeFast: runningFast(3600, 16 * 60) })

        await terminerLeJeune(wrapper)

        expect(hoisted.patch).toHaveBeenCalledTimes(1)
        const [url, payload] = hoisted.patch.mock.calls[0]
        expect(url).toBe('/tools.fasting.update/7')
        // L'heure murale de l'appareil (`AAAA-MM-JJ HH:mm:ss`) partait sans
        // décalage, et le serveur la lisait comme une heure de l'application (#2111).
        expect(payload.end_time).toBe(NOW.toISOString())
        expect(payload.status).toBe('completed')
    })

    it('termine avec l’heure de l’appareil au départ, que le serveur place sur son horloge (#2198)', async () => {
        // La fin prise au geste part avec l'heure de l'appareil au départ, la même : son âge est nul, et le serveur la
        // place sur sa propre horloge, comme le début laissé tel quel. Une horloge d'appareil décalée ne déplace plus
        // ni l'un ni l'autre, et la durée reste l'écart de deux instants lus sur la même horloge.
        const wrapper = await mountPage({ activeFast: runningFast(3600, 16 * 60) })

        await terminerLeJeune(wrapper)

        const [, payload, options] = hoisted.patch.mock.calls[0]
        expect(options.headers).toEqual({ 'X-Heure-De-L-Appareil': NOW.toISOString() })
        expect(options.headers['X-Heure-De-L-Appareil']).toBe(payload.end_time)
    })

    it('demande confirmation avant de terminer, et ne part pas sur un seul appui', async () => {
        const wrapper = await mountPage({ activeFast: runningFast(3600, 16 * 60) })

        await boutonTerminer(wrapper).trigger('click')

        expect(dialogueDeFin(wrapper).props('ouvert')).toBe(true)
        expect(hoisted.patch).not.toHaveBeenCalled()

        await dialogueDeFin(wrapper).vm.$emit('annuler')

        expect(dialogueDeFin(wrapper).props('ouvert')).toBe(false)
        expect(hoisted.patch).not.toHaveBeenCalled()
    })

    it('dit le refus de la fin sous le bouton, en alerte', async () => {
        const wrapper = await mountPage({ activeFast: runningFast(3600, 16 * 60) })
        expect(wrapper.find('[dusk="fasting-end-error"]').exists()).toBe(false)

        wrapper.vm.endForm.errors = { end_time: 'La fin précède le début.' }
        await flushPromises()

        const alerte = wrapper.find('[dusk="fasting-end-error"]')
        expect(alerte.attributes('role')).toBe('alert')
        expect(alerte.text()).toBe('La fin précède le début.')
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

    it('envoie un début laissé tel quel à l’instant du geste, pas à celui où la page l’a prérempli', async () => {
        // Le champ prérempli dit « maintenant ». Ouverte à 12:00 et validée à
        // 12:40, la page envoyait 12:00 : le jeûne commençait quarante minutes
        // avant le geste, et la page les affichait aussitôt écoulées.
        const wrapper = await mountPage({})
        const geste = new Date(NOW.getTime() + 40 * 60 * 1000)

        vi.setSystemTime(geste)
        await wrapper.find('form').trigger('submit')

        expect(hoisted.post.mock.calls[0][1].start_time).toBe(geste.toISOString())
    })

    it('envoie avec un début laissé tel quel l’heure de l’appareil au départ, que le serveur place sur son horloge (#2198)', async () => {
        // Laissé tel quel, le début dit « maintenant », lu sur l'horloge de l'appareil. Une horloge en avance de plus de
        // cinq minutes le faisait refuser comme un début choisi dans le futur. Il part avec l'heure de l'appareil au
        // départ, la même : son âge est nul, et le serveur le place sur sa propre horloge (`X-Heure-De-L-Appareil`).
        const wrapper = await mountPage({})
        const geste = new Date(NOW.getTime() + 40 * 60 * 1000)

        vi.setSystemTime(geste)
        await wrapper.find('form').trigger('submit')

        const [, payload, options] = hoisted.post.mock.calls[0]
        expect(options.headers).toEqual({ 'X-Heure-De-L-Appareil': geste.toISOString() })
        expect(options.headers['X-Heure-De-L-Appareil']).toBe(payload.start_time)
    })

    it('envoie sans l’heure de l’appareil un début changé à la main, que le serveur juge tel quel (#2198)', async () => {
        // Une heure choisie n'est pas un geste : le serveur la garde, et refuse celle qui passe de plus de cinq minutes
        // l'instant de la requête.
        const wrapper = await mountPage({})

        await wrapper
            .find('input[type="datetime-local"]')
            .setValue(localStamp(new Date(NOW.getTime() - 3600 * 1000), 'T'))
        await wrapper.find('form').trigger('submit')

        const [, payload, options] = hoisted.post.mock.calls[0]
        expect(payload.start_time).toBe(new Date(NOW.getTime() - 3600 * 1000).toISOString())
        expect(options?.headers ?? {}).not.toHaveProperty('X-Heure-De-L-Appareil')
    })

    it('remplit de nouveau le début quand le formulaire réapparaît, sans que la page soit remontée', async () => {
        // Terminer passe par Inertia, qui garde l'état : la page n'est pas
        // remontée, et le champ gardait l'heure de son montage, celle du début
        // du jeûne qui vient de finir (#2111).
        const wrapper = await mountPage({ activeFast: runningFast(16 * 3600, 16 * 60) })
        const fin = new Date(NOW.getTime() + 3 * 3600 * 1000)

        vi.setSystemTime(fin)
        await wrapper.setProps({ activeFast: null })

        expect(wrapper.find('input[type="datetime-local"]').element.value).toBe(localStamp(fin, 'T'))

        wrapper.unmount()
    })

    it('montre le refus du serveur, même sans champ associé', async () => {
        const wrapper = await mountPage({})

        wrapper.vm.startForm.errors = { base: 'Un jeûne est déjà en cours.' }
        await wrapper.vm.$nextTick()

        expect(wrapper.find('[dusk="fasting-error"]').text()).toBe('Un jeûne est déjà en cours.')
    })

    it('dit une seule fois le refus d’un champ, sous lui, et garde l’alerte du formulaire pour ce qu’aucun champ ne porte (#2198)', async () => {
        // L'alerte du formulaire montrait la première erreur de n'importe quel champ : le refus d'un début à venir, que
        // le champ « Début » dit déjà, s'affichait et s'annonçait deux fois au lecteur d'écran.
        const wrapper = await mountPage({})
        const refusDuDebut =
            "Ton jeûne ne peut pas commencer dans le futur : vérifie l'heure du début et celle de ton appareil."
        // Ce que chaque alerte affichée annonce, sans la ligature de son icône décorative. L'erreur d'un champ se cache
        // par `v-show`, lue sur son style : `isVisible()` passe par `getComputedStyle`, que jsdom garde d'un état à
        // l'autre quand Vue change `display` par le CSSOM.
        const alertesVisibles = () =>
            wrapper
                .findAll('[role="alert"]')
                .filter((alerte) => alerte.element.style.display !== 'none')
                .map((alerte) => nomAccessible(alerte.element))

        wrapper.vm.startForm.errors = { start_time: refusDuDebut }
        await wrapper.vm.$nextTick()

        expect(alertesVisibles()).toEqual([refusDuDebut])
        expect(wrapper.find('[dusk="fasting-error"]').exists()).toBe(false)

        wrapper.vm.startForm.errors = { type: 'Le type de jeûne sélectionné est invalide.' }
        await wrapper.vm.$nextTick()

        expect(alertesVisibles()).toEqual(['Le type de jeûne sélectionné est invalide.'])

        wrapper.vm.startForm.errors = { start_time: refusDuDebut, base: 'Un jeûne est déjà en cours.' }
        await wrapper.vm.$nextTick()

        expect(alertesVisibles()).toEqual([refusDuDebut, 'Un jeûne est déjà en cours.'])
        expect(wrapper.find('[dusk="fasting-error"]').text()).toBe('Un jeûne est déjà en cours.')

        // La durée cible, que la page tire du type, n'a pas de champ : son refus reste dans l'alerte du formulaire.
        wrapper.vm.startForm.errors = { target_duration_minutes: 'La durée cible ne peut pas dépasser 7 jours.' }
        await wrapper.vm.$nextTick()

        expect(alertesVisibles()).toEqual(['La durée cible ne peut pas dépasser 7 jours.'])
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
        expect(withoutChart.text()).toContain('Pas assez de données pour afficher le graphique')
    })

    it('annonce un historique vide, et se tait dès qu’il y a quelque chose', async () => {
        expect((await mountPage({ history: history([]) })).text()).toContain('Aucun historique de jeûne')

        // Without this half the assertion above holds for a message rendered
        // unconditionally, which is not what "annonce un historique vide" claims.
        expect((await mountPage({ history: history([completed]) })).text()).not.toContain('Aucun historique de jeûne')
    })

    it('ne donne aux lignes de l’historique aucun effet de bouton : elles n’ont pas d’action', async () => {
        const wrapper = await mountPage({ history: history([completed]) })
        const ligne = wrapper.find('[aria-label^="Supprimer le jeûne"]').element.closest('.rounded-2xl')

        expect(ligne.className).not.toMatch(/hover:|active:/)
    })

    it('demande confirmation avant de supprimer', async () => {
        const wrapper = await mountPage({ history: history([completed]) })

        await wrapper.find('[aria-label^="Supprimer le jeûne"]').trigger('click')

        expect(dialogue(wrapper).props('ouvert')).toBe(true)
        expect(hoisted.destroy).not.toHaveBeenCalled()

        await confirmer(wrapper)

        expect(hoisted.destroy).toHaveBeenCalled()
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

describe('Fasting — le compteur quand le jeûne démarre en cours de route', () => {
    it('arme l’horloge sur un jeûne qui apparaît sans remontage', async () => {
        // Le cas ordinaire : on ouvre la page sans jeûne en cours, on appuie sur
        // « Commencer ». `router.post` conserve l'état, donc le composant n'est
        // jamais remonté et `onMounted` ne rejoue pas. L'horloge n'étant armée
        // que là, la carte apparaissait figée à 00:00:00 avec un anneau vide,
        // aussi longtemps que la page restait ouverte.
        const wrapper = await mountPage({ activeFast: null })

        await wrapper.setProps({ activeFast: runningFast(2 * 3600 + 3 * 60 + 9, 16 * 60) })
        await flushPromises()

        expect(elapsedReadout(wrapper)).toBe('02:03:09')

        wrapper.unmount()
    })

    it('continue de compter seconde après seconde', async () => {
        const wrapper = await mountPage({ activeFast: null })

        await wrapper.setProps({ activeFast: runningFast(0, 16 * 60) })
        await flushPromises()

        expect(elapsedReadout(wrapper)).toBe('00:00:00')

        // L'intervalle doit tourner, pas seulement avoir affiché une valeur une
        // fois : c'est la différence entre une horloge et une capture.
        vi.advanceTimersByTime(3000)
        await flushPromises()

        expect(elapsedReadout(wrapper)).toBe('00:00:03')

        wrapper.unmount()
    })

    it('arrête l’horloge quand le jeûne se termine', async () => {
        const wrapper = await mountPage({ activeFast: runningFast(3600, 16 * 60) })

        await wrapper.setProps({ activeFast: null })
        await flushPromises()

        // Sans le désarmement, l'intervalle survivrait au jeûne et continuerait
        // de lire `props.activeFast` à chaque seconde pour rien.
        vi.advanceTimersByTime(5000)
        await flushPromises()

        expect(wrapper.text()).not.toContain('01:00:0')

        wrapper.unmount()
    })
})

/**
 * Un appareil hors du fuseau de l'application (#2111).
 *
 * Le début partait en heure murale de l'appareil (`2026-03-10T08:00` à
 * Toronto), la fin aussi (`2026-03-11 00:00:00`), sans décalage : le serveur
 * lit une telle heure comme une heure de l'application (Paris), et le jeûne
 * s'enregistrait cinq heures trop tôt ; la page relisait cet instant et
 * affichait dès le départ cinq heures écoulées. Le champ reste à l'heure de
 * l'appareil ; ce qui part porte son décalage et désigne l'instant de l'appareil.
 *
 * Le fuseau se pose par `poserLeFuseau` (`tests/js/utils/fuseauDeLAppareil.js`),
 * qui vérifie qu'il est en vigueur à l'instant du cas : sous `--pool=threads`,
 * où Node l'ignore sans erreur, chaque cas tombe sur son nom au lieu de passer
 * sans rien prouver.
 *
 * `tests/Feature/Tools/JeuneALInstantDeLAppareilTest.php` envoie au serveur ces
 * mêmes valeurs, et vérifie qu'il les relit au même instant et sert un jeûne
 * dont l'écoulé est nul au départ.
 */

/** Seize heures après `NOW` : minuit à Toronto, 05:00 à Paris. */
const FIN = new Date('2026-03-11T04:00:00Z')

/** Le jeûne que le serveur sert pour un début reçu à `NOW`, sous la forme d'Eloquent. */
const jeuneServiAuDepart = {
    id: 7,
    type: '16:8',
    start_time: '2026-03-10T12:00:00.000000Z',
    end_time: null,
    target_duration_minutes: 16 * 60,
}

const envoyerLeDebut = async (wrapper) => {
    await wrapper.find('form').trigger('submit')

    expect(hoisted.post).toHaveBeenCalledTimes(1)

    return hoisted.post.mock.calls[0][1].start_time
}

const envoyerLaFin = async (wrapper) => {
    await terminerLeJeune(wrapper)

    expect(hoisted.patch).toHaveBeenCalledTimes(1)

    return hoisted.patch.mock.calls[0][1].end_time
}

describe('Fasting — un appareil à Toronto, hors du fuseau de l’application (#2111)', () => {
    // 12:00Z le 10 mars 2026 : 08:00 à Toronto (UTC-4 depuis le 8 mars), 13:00 à Paris (UTC+1).
    beforeEach(() => poserLeFuseau('America/Toronto', 240, NOW))

    it('garde dans le champ l’heure de l’appareil, et envoie le début à son instant, avec son décalage', async () => {
        const wrapper = await mountPage({})

        expect(wrapper.find('input[type="datetime-local"]').element.value).toBe('2026-03-10T08:00')

        const debut = await envoyerLeDebut(wrapper)

        expect(debut).toMatch(AVEC_SON_DECALAGE)
        expect(Date.parse(debut)).toBe(NOW.getTime())
        expect(debut).toBe('2026-03-10T12:00:00.000Z')
    })

    it('envoie un début corrigé à la main à l’instant que l’appareil lit dans son champ', async () => {
        const wrapper = await mountPage({})

        await wrapper.find('input[type="datetime-local"]').setValue('2026-03-10T06:30')
        const debut = await envoyerLeDebut(wrapper)

        expect(debut).toMatch(AVEC_SON_DECALAGE)
        expect(Date.parse(debut)).toBe(Date.parse('2026-03-10T10:30:00Z'))
    })

    it('relit le jeûne servi au départ à zéro, le termine à l’instant de l’appareil, puis le date dans l’historique à son heure', async () => {
        const wrapper = await mountPage({ activeFast: jeuneServiAuDepart })

        expect(elapsedReadout(wrapper)).toBe('00:00:00')
        expect(wrapper.text()).toContain('16:00:00 restants')
        expect(wrapper.text()).toContain('Débuté : 10/03/2026 08:00')

        vi.setSystemTime(FIN)
        const fin = await envoyerLaFin(wrapper)

        expect(fin).toMatch(AVEC_SON_DECALAGE)
        expect(Date.parse(fin)).toBe(FIN.getTime())
        expect(fin).toBe('2026-03-11T04:00:00.000Z')

        const termine = { ...jeuneServiAuDepart, end_time: '2026-03-11T04:00:00.000000Z', status: 'completed' }
        await wrapper.setProps({ activeFast: null, history: { ...emptyHistory, data: [termine] } })
        await flushPromises()

        expect(wrapper.text()).toContain('10/03/2026 08:00 - 11/03/2026 00:00')
        expect(wrapper.text()).toContain('16:00:00')
        expect(wrapper.findComponent({ name: 'FastingHistoryChart' }).props('data')).toEqual([termine])

        wrapper.unmount()
    })
})

describe('Fasting — un appareil à Paris, dans le fuseau de l’application (#2111)', () => {
    beforeEach(() => poserLeFuseau('Europe/Paris', -60, NOW))

    it('envoie le même instant qu’avant, désormais avec son décalage', async () => {
        const debutPage = await mountPage({})

        // Ce que la page envoyait (« 2026-03-10T13:00 »), lu comme une heure de
        // Paris, désignait déjà cet instant : le jeûne s'enregistre comme avant.
        expect(debutPage.find('input[type="datetime-local"]').element.value).toBe('2026-03-10T13:00')

        const debut = await envoyerLeDebut(debutPage)

        expect(debut).toMatch(AVEC_SON_DECALAGE)
        expect(Date.parse(debut)).toBe(Date.parse('2026-03-10T13:00:00+01:00'))

        const finPage = await mountPage({ activeFast: jeuneServiAuDepart })
        vi.setSystemTime(FIN)
        const fin = await envoyerLaFin(finPage)

        expect(fin).toMatch(AVEC_SON_DECALAGE)
        expect(Date.parse(fin)).toBe(Date.parse('2026-03-11T05:00:00+01:00'))

        finPage.unmount()
    })
})

describe('Fasting — un appareil à Toronto pendant l’heure que le passage à l’heure d’hiver répète (#2111)', () => {
    // 06:30Z le 1er novembre 2026 : 01:30 EST, la seconde fois que Toronto lit
    // 01:30 cette nuit-là (la première, 01:30 EDT, était 05:30Z).
    const SECONDE_OCCURRENCE = new Date('2026-11-01T06:30:00Z')

    beforeEach(() => {
        vi.setSystemTime(SECONDE_OCCURRENCE)

        return poserLeFuseau('America/Toronto', 300, SECONDE_OCCURRENCE)
    })

    it('envoie le début prérempli à l’instant du geste, que l’heure murale du champ ne distingue pas de l’heure d’avant', async () => {
        const wrapper = await mountPage({})

        expect(wrapper.find('input[type="datetime-local"]').element.value).toBe('2026-11-01T01:30')

        // Relu dans son champ, « 01:30 » désigne la première occurrence
        // (05:30Z, ECMAScript) : le jeûne partait une heure avant le geste.
        expect(await envoyerLeDebut(wrapper)).toBe('2026-11-01T06:30:00.000Z')
    })
})

describe('Fasting — le retour de l’en-tête de bureau (#2115, #2116)', () => {
    /*
     * L'en-tête de page (`#header`), qui ne se montre qu'à partir de 1 024 px,
     * porte un lien réduit à une icône vers les outils. Sans nom, un lecteur
     * d'écran l'annonçait « lien », ou lisait son adresse ; ses jumeaux des
     * outils et d'un exercice se nommaient déjà.
     */
    it('se nomme « Retour aux outils », comme ses jumeaux', async () => {
        const wrapper = mount(FastingIndex, {
            props: { activeFast: null, history: emptyHistory },
            global: {
                directives: { press: {} },
                mocks: { route: globalThis.route },
                stubs: { AuthenticatedLayout: layoutStub },
            },
        })
        await flushPromises()

        expect(nomAccessible(wrapper.get('a[href="/tools.index"]').element)).toBe('Retour aux outils')

        wrapper.unmount()
    })

    it('offre 44 px au pouce', async () => {
        const wrapper = mount(FastingIndex, {
            props: { activeFast: null, history: emptyHistory },
            global: {
                directives: { press: {} },
                mocks: { route: globalThis.route },
                stubs: { AuthenticatedLayout: layoutStub },
            },
        })
        await flushPromises()

        expect(cibleDuRendu(wrapper.get('a[href="/tools.index"]').element)).toBeGreaterThanOrEqual(CIBLE)

        wrapper.unmount()
    })
})

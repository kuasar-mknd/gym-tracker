import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: vi.fn() }))

import RestTimer from '@/Components/Workout/RestTimer.vue'
import { triggerHaptic } from '@/composables/useHaptics'
import { dansUnGeste, installerLaSortieWebKit, retirerLaSortieWebKit, sorties } from '../utils/sortieAudioWebKit'

const mountTimer = (props = {}) =>
    mount(RestTimer, {
        props: { duration: 90, autoStart: false, ...props },
        global: { directives: { press: {} }, stubs: { GlassButton: { template: '<button><slot /></button>' } } },
    })

const bar = (wrapper) => wrapper.find('[role="progressbar"]')

/** The countdown the lifter reads, mm:ss. */
const clock = (wrapper) => wrapper.find('[role="timer"]').text()

const button = (wrapper, dusk) => wrapper.find(`[dusk="${dusk}"]`)

const toggleButton = (wrapper) =>
    wrapper.findAll('button').find((b) => ['Pause', 'Démarrer le minuteur'].includes(b.attributes('aria-label')))

beforeEach(() => vi.useFakeTimers())
afterEach(() => vi.useRealTimers())

/**
 * La barre se mesure sur la durée du repos, et le temps restant peut la
 * dépasser : une durée qui raccourcit pendant le décompte le laisse au-dessus
 * (le bouton « +30s », retiré depuis, faisait de même). Le remplissage débordait
 * de sa piste, et la barre annonçait un aria-valuenow au-dessus de son propre
 * aria-valuemax, une valeur qu'un lecteur d'écran peut tenir pour absurde.
 */
describe('RestTimer progress bar', () => {
    it('reports a full bar at the start', () => {
        const wrapper = mountTimer({ duration: 90 })

        expect(bar(wrapper).attributes('aria-valuenow')).toBe('100')
        expect(bar(wrapper).attributes('aria-valuemax')).toBe('100')
    })

    it('never reports more than its own maximum when the rest shrinks mid-count', async () => {
        const wrapper = mountTimer({ duration: 150, autoStart: true })

        // La voie de production : le watcher ne recale `timeLeft` que HORS
        // comptage, donc passer a l'exercice suivant pendant un repos laisse
        // `timeLeft` au-dessus de la nouvelle duree — et la barre a 250 %.
        await wrapper.setProps({ duration: 60 })
        await wrapper.vm.$nextTick()

        const value = Number(bar(wrapper).attributes('aria-valuenow'))
        const max = Number(bar(wrapper).attributes('aria-valuemax'))

        expect(value).toBeLessThanOrEqual(max)
        expect(bar(wrapper).find('div').attributes('style')).toContain('width: 100%')
    })

    it('never reports less than nothing', async () => {
        const wrapper = mountTimer({ duration: 90 })

        // `duration` vient de la page, donc rien ne garantit qu'elle soit
        // sensee : une valeur negative doit rendre une barre a zero, pas une
        // barre a l'envers.
        await wrapper.setProps({ duration: -200 })
        await wrapper.vm.$nextTick()

        expect(Number(bar(wrapper).attributes('aria-valuenow'))).toBeGreaterThanOrEqual(0)
    })

    it('does not divide by a duration of zero', () => {
        const wrapper = mountTimer({ duration: 0 })

        expect(Number(bar(wrapper).attributes('aria-valuenow'))).toBe(0)
    })
})

/**
 * The countdown itself, which nothing covered: the timer is driven by an
 * absolute `endTime` rather than by decrementing a counter, precisely so that a
 * throttled or suspended tab does not lose seconds — and that is the part most
 * easily broken by a "simplification".
 */
describe('RestTimer countdown', () => {
    it('writes the remaining time as m:ss', () => {
        // 65 seconds is a minute and five seconds; the seconds stay padded.
        expect(clock(mountTimer({ duration: 65 }))).toBe('1:05')
        expect(clock(mountTimer({ duration: 90 }))).toBe('1:30')
        expect(clock(mountTimer({ duration: 7 }))).toBe('0:07')
    })

    it('counts down once a second while running', async () => {
        const wrapper = mountTimer({ duration: 90, autoStart: true })

        vi.advanceTimersByTime(5000)
        await wrapper.vm.$nextTick()

        expect(clock(wrapper)).toBe('1:25')
    })

    /*
     * `autoStart` vaut `true` quand la page ne le passe pas, et `Show.vue` ne
     * le passe pas : chaque repos démarre au montage. Le docbloc du composant
     * annonçait `false` (#1513).
     */
    it('démarre au montage quand la page ne lui dit rien', async () => {
        const wrapper = mount(RestTimer, {
            props: { duration: 90 },
            global: { directives: { press: {} }, stubs: { GlassButton: { template: '<button><slot /></button>' } } },
        })

        expect(vi.getTimerCount()).toBe(1)

        vi.advanceTimersByTime(5000)
        await wrapper.vm.$nextTick()

        expect(clock(wrapper)).toBe('1:25')
    })

    it('stays where it is until it is started', async () => {
        const wrapper = mountTimer({ duration: 90, autoStart: false })

        vi.advanceTimersByTime(10000)
        await wrapper.vm.$nextTick()

        expect(clock(wrapper)).toBe('1:30')
    })

    it('recovers the seconds a backgrounded tab did not tick', async () => {
        const wrapper = mountTimer({ duration: 90, autoStart: true })

        // A suspended tab stops firing the interval while the clock keeps
        // moving: one minute passes with no tick at all.
        vi.setSystemTime(Date.now() + 60000)
        document.dispatchEvent(new Event('visibilitychange'))
        await wrapper.vm.$nextTick()

        expect(clock(wrapper)).toBe('0:30')
    })

    it('holds the time still while paused, and resumes from there', async () => {
        const wrapper = mountTimer({ duration: 90, autoStart: true })

        vi.advanceTimersByTime(20000)
        await toggleButton(wrapper).trigger('click')

        vi.advanceTimersByTime(30000)
        await wrapper.vm.$nextTick()
        expect(clock(wrapper)).toBe('1:10')

        await toggleButton(wrapper).trigger('click')
        vi.advanceTimersByTime(10000)
        await wrapper.vm.$nextTick()
        expect(clock(wrapper)).toBe('1:00')
    })

    it('announces the end when the rest runs out', async () => {
        const wrapper = mountTimer({ duration: 3, autoStart: true })

        vi.advanceTimersByTime(3000)
        await wrapper.vm.$nextTick()

        expect(wrapper.get('[role="status"]').text()).toBe('Repos terminé')
        expect(clock(wrapper)).toBe('0:00')
    })

    it('keeps the card on screen saying the rest is over, then closes it', async () => {
        const wrapper = mountTimer({ duration: 3, autoStart: true })

        vi.advanceTimersByTime(3000)
        await wrapper.vm.$nextTick()

        const statut = wrapper.get('[role="status"]')
        expect(statut.text()).toBe('Repos terminé')
        expect(wrapper.emitted('close')).toBeUndefined()

        vi.advanceTimersByTime(3999)
        expect(wrapper.emitted('close')).toBeUndefined()
        vi.advanceTimersByTime(1)
        expect(wrapper.emitted('close')).toHaveLength(1)
    })

    it('takes a new duration after the end, and no longer closes by itself', async () => {
        const wrapper = mountTimer({ duration: 3, autoStart: true })
        vi.advanceTimersByTime(3000)
        await wrapper.vm.$nextTick()
        expect(wrapper.get('[role="status"]').text()).toBe('Repos terminé')

        await wrapper.setProps({ duration: 60 })

        expect(wrapper.get('[role="status"]').text()).toBe('Repos en cours')
        expect(clock(wrapper)).toBe('1:00')
        expect(toggleButton(wrapper).exists()).toBe(true)
        vi.advanceTimersByTime(10000)
        expect(wrapper.emitted('close')).toBeUndefined()
    })

    it('does not close a second time once the card is closed by hand during the end notice', async () => {
        const wrapper = mountTimer({ duration: 3, autoStart: true })
        vi.advanceTimersByTime(3000)
        await wrapper.vm.$nextTick()

        await wrapper.get('[dusk="close-timer-x"]').trigger('click')
        vi.advanceTimersByTime(10000)

        expect(wrapper.emitted('close')).toHaveLength(1)
    })

    it('finishes early when the rest is skipped', async () => {
        const wrapper = mountTimer({ duration: 90, autoStart: true })

        await button(wrapper, 'skip-rest-timer').trigger('click')

        expect(wrapper.get('[role="status"]').text()).toBe('Repos terminé')
        expect(wrapper.emitted('close')).toHaveLength(1)
        expect(clock(wrapper)).toBe('0:00')
    })

    it('vibre pour « Passer » puis pour la fin, sans qu’un second appui efface le motif de fin', async () => {
        vi.mocked(triggerHaptic).mockClear()
        const wrapper = mountTimer({ duration: 90, autoStart: true })

        await button(wrapper, 'skip-rest-timer').trigger('click')

        expect(vi.mocked(triggerHaptic).mock.calls.map(([motif]) => motif)).toEqual(['tap', 'timer'])
        vi.advanceTimersByTime(10000)
        expect(wrapper.emitted('close')).toHaveLength(1)
    })

    it('closes without claiming the rest was completed', async () => {
        const wrapper = mountTimer({ duration: 90, autoStart: true })

        await button(wrapper, 'close-timer-x').trigger('click')

        expect(wrapper.emitted('close')).toHaveLength(1)
        expect(wrapper.get('[role="status"]').text()).toBe('Repos en cours')

        // Closing also stops the countdown; a hidden timer must not fire.
        vi.advanceTimersByTime(120000)
        expect(wrapper.get('[role="status"]').text()).toBe('Repos en cours')
    })

    it('retire Pause et Passer à la fin naturelle du repos, et garde « Fermer »', async () => {
        const wrapper = mountTimer({ duration: 3, autoStart: true })
        expect(toggleButton(wrapper).exists()).toBe(true)
        expect(button(wrapper, 'skip-rest-timer').exists()).toBe(true)

        vi.advanceTimersByTime(3000)
        await wrapper.vm.$nextTick()

        expect(toggleButton(wrapper)).toBeUndefined()
        expect(button(wrapper, 'skip-rest-timer').exists()).toBe(false)
        expect(button(wrapper, 'close-timer-x').exists()).toBe(true)
    })

    describe('le focus, quand Pause et Passer disparaissent', () => {
        let wrapper

        const monterAttache = () => {
            wrapper = mount(RestTimer, {
                props: { duration: 3, autoStart: true },
                attachTo: document.body,
                global: { directives: { press: {} } },
            })

            return wrapper
        }

        afterEach(() => wrapper?.unmount())

        it.each([
            ['Pause', (cible) => toggleButton(cible).element],
            ['Passer', (cible) => button(cible, 'skip-rest-timer').element],
        ])('passe de « %s » à « Fermer le minuteur »', async (_nom, boutonFocalise) => {
            monterAttache()
            boutonFocalise(wrapper).focus()
            expect(document.activeElement).toBe(boutonFocalise(wrapper))

            vi.advanceTimersByTime(3000)
            await wrapper.vm.$nextTick()
            await wrapper.vm.$nextTick()

            expect(document.activeElement).toBe(button(wrapper, 'close-timer-x').element)
        })

        it('ne prend pas le focus à qui écrit ailleurs pendant le repos', async () => {
            const champ = document.createElement('input')
            document.body.appendChild(champ)
            monterAttache()
            champ.focus()

            vi.advanceTimersByTime(3000)
            await wrapper.vm.$nextTick()
            await wrapper.vm.$nextTick()

            expect(document.activeElement).toBe(champ)
            champ.remove()
        })
    })

    it('picks up a new rest duration while it is idle, but not mid-rest', async () => {
        const wrapper = mountTimer({ duration: 90, autoStart: false })

        await wrapper.setProps({ duration: 120 })
        expect(clock(wrapper)).toBe('2:00')

        // The next exercise's default must not overwrite a rest already ticking.
        await toggleButton(wrapper).trigger('click')
        vi.advanceTimersByTime(5000)
        await wrapper.setProps({ duration: 60 })
        expect(clock(wrapper)).toBe('1:55')
    })

    it('leaves no interval running once it is unmounted', () => {
        const wrapper = mountTimer({ duration: 90, autoStart: true })

        expect(vi.getTimerCount()).toBe(1)
        wrapper.unmount()

        expect(vi.getTimerCount()).toBe(0)
    })
})

/**
 * La fin du repos sonne sur un iPhone (#2112). Elle arrive dans le rappel de la
 * minuterie, hors de tout geste, où WebKit ne démarre aucune sortie audio : le
 * minuteur en créait pourtant une à ce moment-là, qui restait muette et ouverte.
 * Monté seul, sans la sortie de la page (`tests/js/pages/reposQuiSonne.test.js`
 * joue la page), il ouvre la sienne dans le geste qui le démarre ou le
 * reprend, la reprend dans « Passer », et la rend en partant.
 */
describe('le bip de fin, minuteur monté seul (#2112)', () => {
    beforeEach(() => installerLaSortieWebKit())
    afterEach(() => retirerLaSortieWebKit())

    it('démarré dans un geste, sonne à la fin naturelle du repos, sur la sortie ouverte par ce geste', async () => {
        const wrapper = mountTimer({ duration: 30, autoStart: false })

        await dansUnGeste(() => toggleButton(wrapper).trigger('click'))

        expect(sorties).toHaveLength(1)
        expect(sorties[0].creeeDansUnGeste).toBe(true)

        vi.advanceTimersByTime(30000)
        await wrapper.vm.$nextTick()

        expect(wrapper.get('[role="status"]').text()).toBe('Repos terminé')
        expect(sorties).toHaveLength(1)
        expect(sorties[0].sonsAudibles).toHaveLength(1)
        expect(sorties[0].sonsAudibles[0]).toMatchObject({ frequence: 880, fin: 0.5 })
    })

    it('repris dans un geste après une pause, reprend la sortie qu’iOS avait interrompue, et la fin sonne', async () => {
        const wrapper = mountTimer({ duration: 30, autoStart: false })

        await dansUnGeste(() => toggleButton(wrapper).trigger('click'))
        vi.advanceTimersByTime(10000)
        await dansUnGeste(() => toggleButton(wrapper).trigger('click'))
        sorties[0].interrompre()

        await dansUnGeste(() => toggleButton(wrapper).trigger('click'))

        expect(sorties[0].reprises.at(-1)).toEqual({ dansUnGeste: true, etat: 'interrupted' })
        expect(sorties[0].state).toBe('running')

        vi.advanceTimersByTime(20000)
        await wrapper.vm.$nextTick()

        expect(sorties).toHaveLength(1)
        expect(sorties[0].sonsAudibles).toHaveLength(1)
    })

    it('« Passer », dans un geste, reprend et fait sonner la sortie que le départ a ouverte', async () => {
        const wrapper = mountTimer({ duration: 90, autoStart: false })

        await dansUnGeste(() => toggleButton(wrapper).trigger('click'))
        expect(sorties).toHaveLength(1)
        sorties[0].interrompre()

        await dansUnGeste(() => button(wrapper, 'skip-rest-timer').trigger('click'))

        expect(wrapper.get('[role="status"]').text()).toBe('Repos terminé')
        expect(sorties).toHaveLength(1)
        expect(sorties[0].reprises.at(-1)).toEqual({ dansUnGeste: true, etat: 'interrupted' })
        expect(sorties[0].sonsAudibles).toHaveLength(1)
    })

    it('n’ouvre aucune sortie hors d’un geste : démarré au montage, il finit sans en laisser une muette', async () => {
        const wrapper = mountTimer({ duration: 30, autoStart: true })

        vi.advanceTimersByTime(30000)
        await wrapper.vm.$nextTick()

        expect(wrapper.get('[role="status"]').text()).toBe('Repos terminé')
        expect(sorties).toHaveLength(0)
    })

    it('démarré au montage, sans sortie : « Passer » l’ouvre dans son geste, y sonne, et la rend en partant', async () => {
        const wrapper = mountTimer({ duration: 90, autoStart: true })
        vi.advanceTimersByTime(10000)
        expect(sorties).toHaveLength(0)

        await dansUnGeste(() => button(wrapper, 'skip-rest-timer').trigger('click'))

        expect(sorties).toHaveLength(1)
        expect(sorties[0].creeeDansUnGeste).toBe(true)
        expect(sorties[0].sonsAudibles).toHaveLength(1)

        wrapper.unmount()

        expect(sorties[0].fermetures).toBe(1)
    })

    it('rend sa sortie en partant', async () => {
        const wrapper = mountTimer({ duration: 30, autoStart: false })

        await dansUnGeste(() => toggleButton(wrapper).trigger('click'))
        wrapper.unmount()

        expect(sorties).toHaveLength(1)
        expect(sorties[0].fermetures).toBe(1)
    })
})

/**
 * L'icône de la bascule (#2122). Le dessin de « Pause », écrit à la main,
 * fermait son second trait par `h4` au lieu de `h-4` : la barre droite se
 * remplissait de 18 à 22 au lieu de 14 à 18, à deux unités du bord, et le
 * pictogramme était décentré de deux unités sur un bouton qu'on touche à chaque
 * repos. La bascule rend désormais les ligatures de la police d'icônes, `pause`
 * et `play_arrow`, comme le minuteur d'intervalles et la barre du bas, à la
 * taille du dessin qu'elles remplacent (20 px, `sm`) ; le bouton se nomme
 * lui-même, l'icône reste décorative.
 */
describe('l’icône de la bascule (#2122)', () => {
    const icone = (wrapper) => toggleButton(wrapper).find('.material-symbols-outlined')

    it('montre la ligature « pause » pendant le décompte, décorative, à 20 px, sans dessin à la main', async () => {
        const wrapper = mountTimer({ duration: 90, autoStart: true })
        // Le départ automatique se fait au montage : le bouton se redessine au tour suivant.
        await wrapper.vm.$nextTick()

        expect(toggleButton(wrapper).attributes('aria-label')).toBe('Pause')
        expect(icone(wrapper).exists()).toBe(true)
        expect(icone(wrapper).text()).toBe('pause')
        expect(icone(wrapper).attributes('aria-hidden')).toBe('true')
        expect(icone(wrapper).classes()).toContain('text-xl')
        expect(toggleButton(wrapper).find('svg').exists()).toBe(false)
    })

    it('montre la ligature « play_arrow » à l’arrêt, puis « pause » une fois reparti', async () => {
        const wrapper = mountTimer({ duration: 90, autoStart: false })

        expect(toggleButton(wrapper).attributes('aria-label')).toBe('Démarrer le minuteur')
        expect(icone(wrapper).text()).toBe('play_arrow')
        expect(icone(wrapper).classes()).toContain('text-xl')
        expect(toggleButton(wrapper).find('svg').exists()).toBe(false)

        await toggleButton(wrapper).trigger('click')

        expect(toggleButton(wrapper).attributes('aria-label')).toBe('Pause')
        expect(icone(wrapper).text()).toBe('pause')
    })
})

describe('le démarrage automatique', () => {
    it('annonce le basculement sans rien écrire lui-même', async () => {
        const wrapper = mountTimer({ duration: 90, autoRestTimer: true })

        await wrapper.find('[dusk="auto-rest-timer"] button[role="switch"]').trigger('click')

        expect(wrapper.emitted('update:autoRestTimer')).toEqual([[false]])
    })

    it('reflète le réglage reçu plutôt qu un état interne', async () => {
        const wrapper = mountTimer({ duration: 90, autoRestTimer: false })

        expect(wrapper.find('[dusk="auto-rest-timer"] button[role="switch"]').attributes('aria-checked')).toBe('false')

        await wrapper.setProps({ autoRestTimer: true })

        expect(wrapper.find('[dusk="auto-rest-timer"] button[role="switch"]').attributes('aria-checked')).toBe('true')
    })
})

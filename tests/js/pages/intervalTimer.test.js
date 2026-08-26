import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'

const routerDelete = vi.fn()

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    router: { delete: (...args) => routerDelete(...args) },
    useForm: (data) => ({ ...data, processing: false, errors: {}, post: vi.fn(), patch: vi.fn(), reset: vi.fn() }),
}))

import IntervalTimer from '@/Pages/Tools/IntervalTimer.vue'

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

beforeAll(() => {
    globalThis.route = (name, param) => `/${name}/${param ?? ''}`
})

const passesSlot = { template: '<div><slot /></div>' }

const mountTimer = (timers = []) =>
    mount(IntervalTimer, {
        props: { timers },
        global: {
            mocks: { route: globalThis.route },
            stubs: {
                AuthenticatedLayout: passesSlot,
                GlassCard: passesSlot,
                GlassButton: { template: '<button><slot /></button>' },
                GlassInput: { template: '<input />' },
            },
        },
    })

/** The 6rem clock, which is the only place the remaining time is written. */
const clock = (wrapper) => wrapper.text().match(/\d{2}:\d{2}/)?.[0]

/** The phase banner above it: PRÊT / ÉCHAUFFEMENT / TRAVAIL / REPOS / TERMINÉ. */
const phaseLabel = (wrapper) =>
    ['ÉCHAUFFEMENT', 'TRAVAIL', 'REPOS', 'TERMINÉ', 'PRÊT'].find((label) => wrapper.text().includes(label))

const playButton = (wrapper) => wrapper.findAll('button').find((b) => b.attributes('aria-label') === 'Démarrer')

const pauseButton = (wrapper) => wrapper.findAll('button').find((b) => b.attributes('aria-label') === 'Mettre en pause')

/** The round counter under the clock, "{{ currentRound }} / {{ rounds }}". */
const currentRound = (wrapper) => wrapper.find('span.font-bold').text()

/** Opens the "Préréglages" tab, where the saved timers are listed. */
const openPresets = (wrapper) => wrapper.findAll('button')[1].trigger('click')

const byLabel = (wrapper, label) => wrapper.findAll('button').find((b) => b.attributes('aria-label') === label)

/** Loads the first saved preset into the runner, which returns to the timer tab. */
const loadPreset = async (wrapper) => {
    await openPresets(wrapper)
    await byLabel(wrapper, 'Charger et lancer').trigger('click')
}

/** 2s warmup, 3s work, 2s rest, 2 rounds — short enough to run to the end. */
const shortPreset = {
    id: 7,
    name: 'Sprint',
    work_seconds: 3,
    rest_seconds: 2,
    rounds: 2,
    warmup_seconds: 2,
}

beforeEach(() => {
    vi.useFakeTimers()

    /*
     * Le mock de suppression ne se réinitialisait pas : tant qu'un seul test
     * l'appelait, personne ne s'en apercevait. Deux tests plus tard, le second
     * voit l'appel du premier et échoue sur un code correct.
     */
    routerDelete.mockClear()
})
afterEach(() => vi.useRealTimers())

/**
 * The runner is a four-state machine — warmup, work, rest, finished — and every
 * transition is driven by a single `tick` reading `timeLeft`. Nothing here was
 * covered: a round that never increments, or a last round that loops instead of
 * finishing, would have been invisible.
 */
describe('IntervalTimer runner', () => {
    it('runs warmup, then alternates work and rest for the configured rounds', async () => {
        const wrapper = mountTimer([shortPreset])
        await loadPreset(wrapper)

        expect(clock(wrapper)).toBe('00:02')
        expect(phaseLabel(wrapper)).toBe('PRÊT')

        await playButton(wrapper).trigger('click')
        expect(phaseLabel(wrapper)).toBe('ÉCHAUFFEMENT')

        // The warmup lasts exactly the 2s it was given, then work starts.
        vi.advanceTimersByTime(2000)
        await wrapper.vm.$nextTick()
        expect(phaseLabel(wrapper)).toBe('TRAVAIL')
        expect(clock(wrapper)).toBe('00:03')

        vi.advanceTimersByTime(3000)
        await wrapper.vm.$nextTick()
        expect(phaseLabel(wrapper)).toBe('REPOS')
        expect(clock(wrapper)).toBe('00:02')
        expect(currentRound(wrapper)).toBe('1')

        // Rest ends: the round counter moves on and work resumes.
        vi.advanceTimersByTime(2000)
        await wrapper.vm.$nextTick()
        expect(phaseLabel(wrapper)).toBe('TRAVAIL')
        expect(currentRound(wrapper)).toBe('2')

        // Second and last round of work: no third round, the session is over.
        vi.advanceTimersByTime(3000)
        await wrapper.vm.$nextTick()
        expect(phaseLabel(wrapper)).toBe('TERMINÉ')
        expect(clock(wrapper)).toBe('00:00')
    })

    it('counts the rounds it was asked for, not one more', async () => {
        const wrapper = mountTimer([{ ...shortPreset, rounds: 3 }])
        await loadPreset(wrapper)
        await playButton(wrapper).trigger('click')

        // warmup 2 + (work 3 + rest 2) x2 + work 3 = 15s of session.
        vi.advanceTimersByTime(15000)
        await wrapper.vm.$nextTick()

        expect(phaseLabel(wrapper)).toBe('TERMINÉ')

        // A fourth round would restart the clock; nothing runs after the end.
        vi.advanceTimersByTime(10000)
        await wrapper.vm.$nextTick()
        expect(phaseLabel(wrapper)).toBe('TERMINÉ')
        expect(clock(wrapper)).toBe('00:00')
    })

    it('writes the remaining time as mm:ss', async () => {
        const wrapper = mountTimer([{ ...shortPreset, warmup_seconds: 0, work_seconds: 65 }])
        await loadPreset(wrapper)

        // 65s is a minute and five seconds, both padded to two digits.
        expect(clock(wrapper)).toBe('01:05')

        await playButton(wrapper).trigger('click')
        vi.advanceTimersByTime(6000)
        await wrapper.vm.$nextTick()
        expect(clock(wrapper)).toBe('00:59')
    })

    it('starts on work when the preset has no warmup', async () => {
        const wrapper = mountTimer([{ ...shortPreset, warmup_seconds: 0 }])
        await loadPreset(wrapper)

        expect(clock(wrapper)).toBe('00:03')

        await playButton(wrapper).trigger('click')
        expect(phaseLabel(wrapper)).toBe('TRAVAIL')
    })

    it('freezes the clock while paused', async () => {
        const wrapper = mountTimer([shortPreset])
        await loadPreset(wrapper)
        await playButton(wrapper).trigger('click')

        vi.advanceTimersByTime(1000)
        await wrapper.vm.$nextTick()
        expect(clock(wrapper)).toBe('00:01')

        await pauseButton(wrapper).trigger('click')
        vi.advanceTimersByTime(10000)
        await wrapper.vm.$nextTick()

        expect(clock(wrapper)).toBe('00:01')
        expect(phaseLabel(wrapper)).toBe('ÉCHAUFFEMENT')
    })

    it('resumes from where the pause left it', async () => {
        const wrapper = mountTimer([{ ...shortPreset, warmup_seconds: 10 }])
        await loadPreset(wrapper)
        await playButton(wrapper).trigger('click')

        vi.advanceTimersByTime(4000)
        await pauseButton(wrapper).trigger('click')
        await playButton(wrapper).trigger('click')

        vi.advanceTimersByTime(1000)
        await wrapper.vm.$nextTick()
        expect(clock(wrapper)).toBe('00:05')
    })

    it('sends the runner back to the start of the warmup on reset', async () => {
        const wrapper = mountTimer([shortPreset])
        await loadPreset(wrapper)
        await playButton(wrapper).trigger('click')

        // 2s warmup + 3s work + 2s rest puts the runner in round 2.
        vi.advanceTimersByTime(8000)
        await wrapper.vm.$nextTick()
        expect(currentRound(wrapper)).toBe('2')

        await byLabel(wrapper, 'Réinitialiser').trigger('click')

        expect(clock(wrapper)).toBe('00:02')
        expect(phaseLabel(wrapper)).toBe('PRÊT')
        expect(currentRound(wrapper)).toBe('1')

        // Reset also stops the clock, it does not merely rewind it.
        vi.advanceTimersByTime(5000)
        await wrapper.vm.$nextTick()
        expect(clock(wrapper)).toBe('00:02')
    })
})

describe('IntervalTimer presets', () => {
    it('loads the preset the user picked into the runner', async () => {
        // Three figures that differ from each other: the rest and the warmup of
        // `shortPreset` are both 2s, which makes those two tiles
        // indistinguishable and a swap between them read as correct.
        const wrapper = mountTimer([{ ...shortPreset, work_seconds: 3, rest_seconds: 4, warmup_seconds: 5 }])

        await loadPreset(wrapper)

        // The three legend tiles read work / rest / warmup, in that order.
        const legend = wrapper.findAll('.glass-panel').map((tile) => tile.text())
        expect(legend[0]).toContain('3s')
        expect(legend[1]).toContain('4s')
        expect(legend[2]).toContain('5s')
        expect(wrapper.text()).toContain('Sprint')
    })

    it('treats a preset without a warmup as a zero-second warmup', async () => {
        const wrapper = mountTimer([{ ...shortPreset, warmup_seconds: null }])
        await loadPreset(wrapper)

        expect(wrapper.findAll('.glass-panel')[2].text()).toContain('0s')
        expect(clock(wrapper)).toBe('00:03')
    })

    it('summarises each saved preset by rounds, work and rest', async () => {
        const wrapper = mountTimer([shortPreset])

        await openPresets(wrapper)

        expect(wrapper.text()).toContain('2x 3s Travail / 2s Repos')
    })

    /*
     * Both halves are needed. On its own the first passes just as well against
     * a message with no condition on it at all, which would then sit above the
     * list of presets it claims are not there.
     */
    it('says the list is empty instead of showing nothing, and only then', async () => {
        const empty = mountTimer([])
        const stocked = mountTimer([shortPreset])

        await openPresets(empty)
        await openPresets(stocked)

        expect(empty.text()).toContain('Aucun minuteur enregistré.')
        expect(stocked.text()).not.toContain('Aucun minuteur enregistré.')
    })

    it('ne supprime un minuteur qu’une fois la confirmation donnée', async () => {
        const wrapper = mountTimer([shortPreset])
        await openPresets(wrapper)

        await byLabel(wrapper, 'Supprimer').trigger('click')

        expect(dialogue(wrapper).props('ouvert')).toBe(true)
        expect(routerDelete).not.toHaveBeenCalled()

        await confirmer(wrapper)

        expect(routerDelete.mock.calls[0][0]).toBe('/tools.interval-timer.destroy/7')
    })

    it('laisse le minuteur tranquille quand on annule', async () => {
        const wrapper = mountTimer([shortPreset])
        await openPresets(wrapper)

        await byLabel(wrapper, 'Supprimer').trigger('click')
        await dialogue(wrapper).vm.$emit('annuler')

        expect(dialogue(wrapper).props('ouvert')).toBe(false)
        expect(routerDelete).not.toHaveBeenCalled()
    })
})

import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'

const post = vi.fn()
const patch = vi.fn()
let form

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await vi.importActual('vue')

    return {
        Head: { template: '<div />' },
        router: { delete: vi.fn() },
        // Reactive: `isEditing` is a computed over `form.id`, and it decides the
        // heading, which buttons are on screen and whether the save is a POST
        // or a PATCH. A plain object never invalidates it, so the page would
        // stay in "create" mode however much the test typed.
        useForm: (data) => {
            form = reactive({
                ...data,
                processing: false,
                errors: {},
                post,
                patch,
                reset: vi.fn(function reset() {
                    Object.assign(this, { ...data })
                }),
            })

            return form
        },
    }
})

import IntervalTimer from '@/Pages/Tools/IntervalTimer.vue'
import { passesSlot } from './pageStubs'

beforeAll(() => {
    globalThis.route = (name, param) => `/${name}/${param ?? ''}`
})

/** Every oscillator the page asked for, in the order it asked. */
let beeps

class FakeOscillator {
    constructor(ctx) {
        this.ctx = ctx
        this.type = null
        this.frequency = { value: null }
        this.startedAt = null
        this.stoppedAt = null
    }
    connect() {}
    start() {
        this.startedAt = this.ctx.currentTime
    }
    stop(when) {
        this.stoppedAt = when
    }
}

class FakeAudioContext {
    constructor() {
        this.currentTime = 0
        this.state = 'running'
        this.destination = {}
        this.closed = false
        this.resumed = 0
        FakeAudioContext.instances.push(this)
    }
    createOscillator() {
        const osc = new FakeOscillator(this)
        beeps.push(osc)

        return osc
    }
    createGain() {
        return { connect: () => {}, gain: { exponentialRampToValueAtTime: () => {} } }
    }
    resume() {
        this.resumed += 1
        this.state = 'running'
    }
    close() {
        this.closed = true
    }
}

beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()
    beeps = []
    FakeAudioContext.instances = []
    window.AudioContext = FakeAudioContext
})

afterEach(() => {
    vi.useRealTimers()
    delete window.AudioContext
    delete window.webkitAudioContext
})

const preset = (overrides = {}) => ({
    id: 7,
    name: 'Sprint',
    work_seconds: 3,
    rest_seconds: 2,
    rounds: 2,
    warmup_seconds: 2,
    ...overrides,
})

const mountTimer = (timers = []) =>
    mount(IntervalTimer, {
        props: { timers: structuredClone(timers) },
        global: {
            mocks: { route: globalThis.route },
            directives: { press: {} },
            stubs: { AuthenticatedLayout: passesSlot, GlassCard: passesSlot },
        },
    })

/** Opens the "Préréglages" tab, where the form and the saved list live. */
const openPresets = (wrapper) => wrapper.findAll('button')[1].trigger('click')

const byText = (wrapper, text) => wrapper.findAll('button').find((button) => button.text().trim() === text)

const byLabel = (wrapper, label) => wrapper.findAll('button').find((b) => b.attributes('aria-label') === label)

/** The five form boxes, in the order the form lays them out. */
const fields = (wrapper) => {
    const inputs = wrapper.findAll('input')

    return {
        name: inputs[0],
        work: inputs[1],
        rest: inputs[2],
        rounds: inputs[3],
        warmup: inputs[4],
    }
}

const heading = (wrapper) => wrapper.find('h3').text()

const clock = (wrapper) => wrapper.text().match(/\d{2}:\d{2}/)?.[0]

/** The three legend tiles under the clock: work / rest / warmup. */
const legend = (wrapper) => wrapper.findAll('.glass-panel').map((tile) => tile.text())

/*
 * The runner was tested; the form beside it was not. Everything below — which
 * verb the save uses, what the form holds afterwards, and what the preview
 * hands to the runner — was dead ground, and all of it is reachable in two
 * taps from the presets tab.
 */
describe('saving a new preset', () => {
    it('posts to the create route rather than patching', async () => {
        const wrapper = mountTimer()
        await openPresets(wrapper)

        expect(heading(wrapper)).toBe('Nouveau minuteur')

        await wrapper.find('form').trigger('submit')

        expect(post).toHaveBeenCalledTimes(1)
        expect(post.mock.calls[0][0]).toBe('/tools.interval-timer.store/')
        expect(patch).not.toHaveBeenCalled()
    })

    it('empties the form and stays on the list once the server accepts', async () => {
        const wrapper = mountTimer()
        await openPresets(wrapper)

        const boxes = fields(wrapper)
        await boxes.name.setValue('EMOM')
        await boxes.work.setValue('60')

        await wrapper.find('form').trigger('submit')
        post.mock.calls[0][1].onSuccess()
        await wrapper.vm.$nextTick()

        // Left as typed, the next save would silently create a second copy of
        // the preset the user just made.
        expect(form.name).toBe('Tabata')
        expect(form.work_seconds).toBe(20)
        expect(wrapper.vm.activeTab).toBe('config')
    })

    it('writes each box into the field it is labelled with', async () => {
        const wrapper = mountTimer()
        await openPresets(wrapper)

        // Five values that differ from each other and from the defaults, so a
        // v-model bound to the wrong key cannot pass.
        const boxes = fields(wrapper)
        await boxes.name.setValue('EMOM')
        await boxes.work.setValue('45')
        await boxes.rest.setValue('15')
        await boxes.rounds.setValue('12')
        await boxes.warmup.setValue('30')

        expect(form.name).toBe('EMOM')
        // Numbers, not the strings the DOM hands over: they are fed straight to
        // `setInterval` arithmetic, where "45" - 1 is 44 but "45" + 1 is "451".
        expect([form.work_seconds, form.rest_seconds, form.rounds, form.warmup_seconds]).toEqual([45, 15, 12, 30])
    })
})

describe('editing a saved preset', () => {
    it('opens the form on the preset that was picked', async () => {
        const wrapper = mountTimer([
            preset({ id: 9, name: 'Sprint', work_seconds: 30, rest_seconds: 90, rounds: 6, warmup_seconds: 45 }),
        ])
        const scrollTo = vi.spyOn(window, 'scrollTo').mockImplementation(() => {})

        await openPresets(wrapper)
        await byLabel(wrapper, 'Modifier le minuteur').trigger('click')

        expect(heading(wrapper)).toBe('Modifier le minuteur')
        expect(form.id).toBe(9)
        expect(form.name).toBe('Sprint')
        expect([form.work_seconds, form.rest_seconds, form.rounds, form.warmup_seconds]).toEqual([30, 90, 6, 45])
        // The form sits above the list; on a phone the tap leaves it off screen.
        expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' })

        scrollTo.mockRestore()
    })

    it('patches the preset it was opened on, by id', async () => {
        const wrapper = mountTimer([preset({ id: 9 })])
        vi.spyOn(window, 'scrollTo').mockImplementation(() => {})

        await openPresets(wrapper)
        await byLabel(wrapper, 'Modifier le minuteur').trigger('click')
        await wrapper.find('form').trigger('submit')

        expect(patch).toHaveBeenCalledTimes(1)
        expect(patch.mock.calls[0][0]).toBe('/tools.interval-timer.update/9')
        expect(post).not.toHaveBeenCalled()
    })

    it('closes the edit once the server accepts', async () => {
        const wrapper = mountTimer([preset({ id: 9 })])
        vi.spyOn(window, 'scrollTo').mockImplementation(() => {})

        await openPresets(wrapper)
        await byLabel(wrapper, 'Modifier le minuteur').trigger('click')
        await wrapper.find('form').trigger('submit')

        patch.mock.calls[0][1].onSuccess()
        await wrapper.vm.$nextTick()

        // Still holding the id, the next save would overwrite the preset the
        // user thought they had finished with.
        expect(form.id).toBeNull()
        expect(heading(wrapper)).toBe('Nouveau minuteur')
    })

    it('offers a way out of the edit, and only while one is open', async () => {
        const wrapper = mountTimer([preset({ id: 9, name: 'Sprint' })])
        vi.spyOn(window, 'scrollTo').mockImplementation(() => {})

        await openPresets(wrapper)
        expect(byText(wrapper, 'Annuler')).toBeUndefined()

        await byLabel(wrapper, 'Modifier le minuteur').trigger('click')
        await byText(wrapper, 'Annuler').trigger('click')

        expect(form.id).toBeNull()
        expect(form.name).toBe('Tabata')
        expect(heading(wrapper)).toBe('Nouveau minuteur')
    })

    it('hides the preview while a preset is being edited', async () => {
        const wrapper = mountTimer([preset({ id: 9 })])
        vi.spyOn(window, 'scrollTo').mockImplementation(() => {})

        await openPresets(wrapper)
        expect(byText(wrapper, 'Lancer')).toBeDefined()

        await byLabel(wrapper, 'Modifier le minuteur').trigger('click')

        // "Lancer" throws away the id along with everything else; offered here
        // it would quietly turn an edit into a new preset.
        expect(byText(wrapper, 'Lancer')).toBeUndefined()
    })
})

describe('previewing what is in the form', () => {
    it('runs the typed values without saving them', async () => {
        const wrapper = mountTimer()
        await openPresets(wrapper)

        const boxes = fields(wrapper)
        await boxes.name.setValue('EMOM')
        await boxes.work.setValue('45')
        await boxes.rest.setValue('15')
        await boxes.rounds.setValue('12')
        await boxes.warmup.setValue('30')

        await byText(wrapper, 'Lancer').trigger('click')

        expect(wrapper.vm.activeTab).toBe('timer')
        expect(post).not.toHaveBeenCalled()
        // Tile by tile: work, rest, warmup all differ, so a pair swapped
        // between them cannot pass.
        expect(legend(wrapper)[0]).toContain('45s')
        expect(legend(wrapper)[1]).toContain('15s')
        expect(legend(wrapper)[2]).toContain('30s')
        expect(wrapper.text()).toContain('EMOM')
        expect(wrapper.text()).toContain('/ 12')
        // The runner opens on the warmup, stopped.
        expect(clock(wrapper)).toBe('00:30')
    })

    it('names an unnamed preview rather than showing a blank line', async () => {
        const wrapper = mountTimer()
        await openPresets(wrapper)

        await fields(wrapper).name.setValue('')
        await byText(wrapper, 'Lancer').trigger('click')

        expect(wrapper.text()).toContain('Custom')
    })

    it('reads an emptied warmup box as no warmup, not as NaN', async () => {
        const wrapper = mountTimer()
        await openPresets(wrapper)

        await fields(wrapper).warmup.setValue('')
        await fields(wrapper).work.setValue('25')
        await byText(wrapper, 'Lancer').trigger('click')

        // An empty number box hands back an empty string; `NaNs` on the tile
        // and `NaN:NaN` on the clock is what that used to render as.
        expect(legend(wrapper)[2]).toContain('0s')
        expect(clock(wrapper)).toBe('00:25')
    })

    it('loads a saved preset when its name is tapped, not only its play button', async () => {
        const wrapper = mountTimer([
            preset({ id: 9, name: 'Sprint', work_seconds: 30, rest_seconds: 90, warmup_seconds: 45 }),
        ])

        await openPresets(wrapper)
        await wrapper.find('.cursor-pointer').trigger('click')

        expect(wrapper.vm.activeTab).toBe('timer')
        expect(legend(wrapper)[0]).toContain('30s')
        expect(legend(wrapper)[1]).toContain('90s')
        expect(legend(wrapper)[2]).toContain('45s')
    })
})

describe('the beeps', () => {
    /** Start a runner on a preset whose phases are long enough to tell apart. */
    const runPreset = async (overrides) => {
        const wrapper = mountTimer([preset(overrides)])
        await openPresets(wrapper)
        await byLabel(wrapper, 'Charger et lancer').trigger('click')
        await byLabel(wrapper, 'Démarrer').trigger('click')

        return wrapper
    }

    it('counts the last three seconds down, and not the fourth', async () => {
        const wrapper = await runPreset({ warmup_seconds: 0, work_seconds: 6, rest_seconds: 5, rounds: 2 })

        // 6 -> 5 -> 4: still far enough out to say nothing.
        vi.advanceTimersByTime(2000)
        await wrapper.vm.$nextTick()
        expect(beeps).toHaveLength(0)

        // The very next tick reaches 3, which is the boundary the countdown
        // starts on. One second either side of it is the whole assertion.
        vi.advanceTimersByTime(1000)
        await wrapper.vm.$nextTick()
        expect(beeps.map((osc) => osc.frequency.value)).toEqual([440])

        vi.advanceTimersByTime(2000)
        await wrapper.vm.$nextTick()
        expect(beeps.map((osc) => osc.frequency.value)).toEqual([440, 440, 440])
    })

    it('marks a phase change with a different note from the countdown', async () => {
        const wrapper = await runPreset({ warmup_seconds: 0, work_seconds: 6, rest_seconds: 5, rounds: 2 })

        vi.advanceTimersByTime(6000)
        await wrapper.vm.$nextTick()

        // Three countdown beeps, then the change; the pitch is the only thing
        // separating "nearly there" from "go".
        expect(beeps.map((osc) => osc.frequency.value)).toEqual([440, 440, 440, 880])
        expect(beeps.at(-1).type).toBe('sine')
    })

    it('ends on a note of its own', async () => {
        const wrapper = await runPreset({ warmup_seconds: 0, work_seconds: 4, rest_seconds: 4, rounds: 1 })

        vi.advanceTimersByTime(4000)
        await wrapper.vm.$nextTick()

        expect(beeps.at(-1).frequency.value).toBe(1200)
        // 600 ms, against 100 ms for a countdown tick.
        expect(beeps.at(-1).stoppedAt).toBeCloseTo(0.6, 5)
    })

    it('wakes an audio context the browser had suspended', async () => {
        const wrapper = mountTimer([preset()])
        await openPresets(wrapper)
        await byLabel(wrapper, 'Charger et lancer').trigger('click')

        await byLabel(wrapper, 'Démarrer').trigger('click')
        const [ctx] = FakeAudioContext.instances
        expect(ctx.resumed).toBe(0)

        // Browsers suspend the context when the tab loses focus; a paused timer
        // resumed after that came back silent.
        await byLabel(wrapper, 'Mettre en pause').trigger('click')
        ctx.state = 'suspended'
        await byLabel(wrapper, 'Démarrer').trigger('click')

        expect(ctx.resumed).toBe(1)
        expect(FakeAudioContext.instances).toHaveLength(1)
    })

    it('keeps running on a browser with no audio at all', async () => {
        delete window.AudioContext

        const wrapper = await runPreset({ warmup_seconds: 0, work_seconds: 4, rest_seconds: 4, rounds: 1 })

        vi.advanceTimersByTime(4000)
        await wrapper.vm.$nextTick()

        // No context to build an oscillator on. The session still has to reach
        // its end rather than throwing out of the tick.
        expect(beeps).toHaveLength(0)
        expect(wrapper.text()).toContain('TERMINÉ')
    })

    it('swallows an audio failure rather than stopping the session', async () => {
        const error = vi.spyOn(console, 'error').mockImplementation(() => {})
        FakeAudioContext.prototype.createOscillator = () => {
            throw new Error('no output device')
        }

        const wrapper = await runPreset({ warmup_seconds: 0, work_seconds: 4, rest_seconds: 4, rounds: 1 })

        vi.advanceTimersByTime(4000)
        await wrapper.vm.$nextTick()

        expect(wrapper.text()).toContain('TERMINÉ')
        expect(error).toHaveBeenCalled()

        error.mockRestore()
        delete FakeAudioContext.prototype.createOscillator
    })

    it('gives the audio context back when the page is left', async () => {
        const wrapper = mountTimer([preset()])
        await openPresets(wrapper)
        await byLabel(wrapper, 'Charger et lancer').trigger('click')
        await byLabel(wrapper, 'Démarrer').trigger('click')

        const [ctx] = FakeAudioContext.instances

        wrapper.unmount()

        // A context left open holds the audio hardware awake for the rest of
        // the session, and the interval left running keeps ticking a timer
        // nobody can see.
        expect(ctx.closed).toBe(true)
        expect(vi.getTimerCount()).toBe(0)
    })
})

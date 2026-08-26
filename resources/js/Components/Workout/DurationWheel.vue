<script setup>
/**
 * Components/Workout/DurationWheel.vue
 *
 * A duration, picked on hh:mm:ss wheels instead of typed into a time input.
 *
 * `<input type="time">` cannot express this value on the platform the app is
 * used on. WebKit renders it with two components whatever `step` says, so an
 * iPhone shows a 30-second set as `00:00` and commits `"00:02"` — measured, not
 * assumed. Seconds are neither readable nor enterable there, which for holds and
 * intervals is most of what the field is for.
 *
 * The wheel is a scroll container with `scroll-snap`, so the momentum, the
 * deceleration and the rubber-banding are the platform's own rather than
 * something reimplemented in JS. That is what makes it feel native; the rotateX
 * per row is only the cylinder.
 *
 * No haptics on iOS. WebKit has never shipped the Vibration API, and the
 * `<input switch>` trick that stands in for it has needed a *click* since
 * iOS 18.4 — a drag does not grant it — so a wheel cannot tick. triggerHaptic
 * already no-ops where the API is absent, so Android gets the detent and iOS
 * quietly does not.
 */
import { computed, nextTick, ref, watch } from 'vue'
import Modal from '@/Components/UI/Modal.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import { triggerHaptic } from '@/composables/useHaptics'

const props = defineProps({
    /** The duration in whole seconds, or null when the set has none. */
    modelValue: { type: Number, default: null },
    disabled: { type: Boolean, default: false },
    /** Names the control for assistive technology, e.g. "Durée, série 1, Planche". */
    label: { type: String, required: true },
    dusk: { type: String, default: null },
    /**
     * Whether the trigger takes the row's spare width. A timed row holds nothing
     * else, so it should; a cardio row shares with the distance, and a duration
     * that grew would squeeze that to a sliver — hh:mm:ss needs a fixed ~100px
     * and no more.
     */
    fill: { type: Boolean, default: true },
})

const emit = defineEmits(['update:modelValue'])

/** Must match --item in the stylesheet below; the scroll maths is in these units. */
const ITEM = 44

const COLUMNS = [
    { key: 'hours', max: 23, unit: 'h', label: 'Heures' },
    { key: 'minutes', max: 59, unit: 'min', label: 'Minutes' },
    { key: 'seconds', max: 59, unit: 's', label: 'Secondes' },
]

const open = ref(false)
const draft = ref({ hours: 0, minutes: 0, seconds: 0 })
const columnRefs = ref({})

const pad = (n) => String(n).padStart(2, '0')

/**
 * Reports the seconds it was given, without rounding them into the wheel's
 * range. The wheel stops at 23 hours; a set that somehow holds more than that
 * must still be displayed as what it is rather than as a smaller, plausible
 * number — showing 23:00:00 for a 25-hour value is the same lie the old
 * `new Date(s * 1000).toISOString()` told when it wrapped past midnight.
 */
const split = (total) => {
    const seconds = Number.isFinite(Number(total)) ? Math.max(Math.floor(Number(total)), 0) : 0

    return {
        hours: Math.floor(seconds / 3600),
        minutes: Math.floor((seconds % 3600) / 60),
        seconds: seconds % 60,
    }
}

/** The same, brought inside what the wheels can actually represent. */
const splitForWheels = (total) => {
    const parts = split(total)

    return { ...parts, hours: Math.min(parts.hours, 23) }
}

const formatted = computed(() => {
    const { hours, minutes, seconds } = split(props.modelValue ?? 0)

    return `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`
})

/** Spoken rather than read: "00:10:00" is not a duration to a screen reader. */
const spoken = computed(() => {
    const { hours, minutes, seconds } = split(props.modelValue ?? 0)
    const parts = []

    if (hours) parts.push(`${hours} heure${hours > 1 ? 's' : ''}`)
    if (minutes) parts.push(`${minutes} minute${minutes > 1 ? 's' : ''}`)
    if (seconds) parts.push(`${seconds} seconde${seconds > 1 ? 's' : ''}`)

    return parts.length ? parts.join(' ') : 'aucune durée'
})

/**
 * The cylinder. Applied to an inner span and never to the row itself: the snap
 * position is computed from the transformed box, so tilting the snap target
 * moves the very thing it is snapping to and the wheel settles off-centre.
 */
const curve = (column) => {
    if (!column) return

    const middle = column.scrollTop + column.clientHeight / 2

    for (const row of column.querySelectorAll('[data-row]')) {
        const offset = row.offsetTop + ITEM / 2 - middle
        const degrees = Math.max(Math.min((offset / 90) * (180 / Math.PI), 90), -90)
        const inner = row.firstElementChild

        inner.style.transform = `rotateX(${-degrees}deg) scale(${Math.max(1 - Math.abs(offset) / 400, 0.8)})`
        inner.style.opacity = String(Math.max(1 - Math.abs(offset) / (ITEM * 3), 0.2))
    }
}

const onScroll = (key) => {
    const column = columnRefs.value[key]
    if (!column) return

    curve(column)

    const value = Math.min(Math.max(Math.round(column.scrollTop / ITEM), 0), COLUMNS.find((c) => c.key === key).max)

    if (draft.value[key] !== value) {
        draft.value[key] = value
        triggerHaptic('selection')
    }
}

/**
 * Assigns scrollTop rather than calling scrollTo: the jump is meant to be
 * instant here — a keyboard step that animated would fight the next keypress —
 * and Element.scrollTo is not implemented in jsdom, which would put the wheel
 * out of reach of a unit test for no gain.
 */
const scrollTo = (key, value) => {
    const column = columnRefs.value[key]
    if (!column) return

    column.scrollTop = value * ITEM
    curve(column)
}

/** Keyboard, which is also the only way an automated test can drive a wheel. */
const onKey = (event, column) => {
    const step = { ArrowUp: -1, ArrowDown: 1, PageUp: -10, PageDown: 10 }[event.key]
    let target

    if (step !== undefined) {
        target = draft.value[column.key] + step
    } else if (event.key === 'Home') {
        target = 0
    } else if (event.key === 'End') {
        target = column.max
    } else {
        return
    }

    event.preventDefault()
    const next = Math.min(Math.max(target, 0), column.max)
    draft.value[column.key] = next
    scrollTo(column.key, next)
    triggerHaptic('selection')
}

const show = async () => {
    if (props.disabled) return

    draft.value = splitForWheels(props.modelValue ?? 0)
    open.value = true

    await nextTick()
    for (const column of COLUMNS) {
        scrollTo(column.key, draft.value[column.key])
    }
}

const confirm = () => {
    emit('update:modelValue', draft.value.hours * 3600 + draft.value.minutes * 60 + draft.value.seconds)
    open.value = false
}

watch(open, (isOpen) => {
    if (!isOpen) return

    nextTick(() => COLUMNS.forEach((column) => scrollTo(column.key, draft.value[column.key])))
})

const setColumnRef = (key) => (element) => {
    columnRefs.value[key] = element
}
</script>

<template>
    <button
        type="button"
        :disabled="disabled"
        :dusk="dusk"
        :aria-label="`${label} : ${spoken}`"
        :data-seconds="modelValue ?? ''"
        class="text-text-main border-border h-11 shrink-0 rounded-xl border-2 px-2 text-center font-bold tabular-nums disabled:opacity-60"
        :class="fill ? 'w-full min-w-32 flex-1' : 'w-auto'"
        @click="show"
    >
        {{ formatted }}
    </button>

    <Modal :show="open" position="bottom" max-width="sm" aria-labelledby="duration-wheel-title" @close="open = false">
        <div class="p-5">
            <h2
                id="duration-wheel-title"
                class="font-display text-text-main mb-4 text-center text-lg font-black uppercase italic"
            >
                Durée
            </h2>

            <div class="wheel-frame">
                <div
                    v-for="column in COLUMNS"
                    :key="column.key"
                    :ref="setColumnRef(column.key)"
                    class="wheel-column"
                    role="spinbutton"
                    tabindex="0"
                    :dusk="dusk ? `${dusk}-${column.key}` : null"
                    :aria-label="column.label"
                    :aria-valuemin="0"
                    :aria-valuemax="column.max"
                    :aria-valuenow="draft[column.key]"
                    :aria-valuetext="`${draft[column.key]} ${column.label.toLowerCase()}`"
                    @scroll.passive="onScroll(column.key)"
                    @keydown="onKey($event, column)"
                >
                    <div class="wheel-spacer" aria-hidden="true"></div>
                    <div v-for="n in column.max + 1" :key="n" data-row class="wheel-row">
                        <span>{{ pad(n - 1) }}</span>
                    </div>
                    <div class="wheel-spacer" aria-hidden="true"></div>
                </div>

                <div class="wheel-band" aria-hidden="true"></div>
                <div class="wheel-units" aria-hidden="true">
                    <span v-for="column in COLUMNS" :key="column.key">{{ column.unit }}</span>
                </div>
            </div>

            <div class="mt-5 flex gap-3">
                <GlassButton type="button" variant="secondary" class="flex-1" @click="open = false"
                    >Annuler</GlassButton
                >
                <GlassButton
                    type="button"
                    variant="primary"
                    class="flex-1"
                    :dusk="dusk ? `${dusk}-confirm` : null"
                    @click="confirm"
                >
                    OK
                </GlassButton>
            </div>
        </div>
    </Modal>
</template>

<style scoped>
.wheel-frame {
    --item: 44px;
    position: relative;
    display: flex;
    justify-content: center;
    gap: 6px;
    height: calc(var(--item) * 5);
    overflow: hidden;
}

.wheel-column {
    height: 100%;
    width: 76px;
    overflow-y: scroll;
    scroll-snap-type: y mandatory;
    scrollbar-width: none;
    perspective: 900px;
    text-align: center;
    outline-offset: -2px;
}

.wheel-column::-webkit-scrollbar {
    display: none;
}

/* The snap target itself is never transformed — see curve(). */
.wheel-row {
    height: var(--item);
    scroll-snap-align: center;
}

.wheel-row > span {
    display: block;
    height: var(--item);
    line-height: var(--item);
    font-size: 22px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    will-change: transform, opacity;
}

.wheel-spacer {
    height: calc(var(--item) * 2);
}

.wheel-band {
    position: absolute;
    inset-inline: 0;
    top: calc(var(--item) * 2);
    height: var(--item);
    border-radius: 12px;
    background: rgb(from var(--color-border-strong) r g b / 0.5);
    pointer-events: none;
}

.wheel-units {
    position: absolute;
    inset-inline: 0;
    top: calc(var(--item) * 2);
    height: var(--item);
    display: flex;
    justify-content: center;
    gap: 6px;
    pointer-events: none;
}

.wheel-units span {
    width: 76px;
    line-height: var(--item);
    padding-left: 52px;
    font-size: 11px;
    font-weight: 700;
    opacity: 0.55;
}

@media (prefers-reduced-motion: reduce) {
    .wheel-column {
        scroll-behavior: auto;
    }
}
</style>

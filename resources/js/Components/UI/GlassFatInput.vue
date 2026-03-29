<script setup>
/**
 * GlassFatInput.vue
 *
 * A specialized numeric input component designed with a "Glass" aesthetic.
 * It features a large, centered input field with integrated increment (+) and
 * decrement (-) buttons that appear on hover or focus. It's intended for
 * numeric values where quick adjustments are common, like weight or repetitions.
 *
 * The component supports custom validation error display, haptic feedback on
 * button presses, and automatic input selection on focus.
 */
import { computed, useAttrs, getCurrentInstance } from 'vue'
import InputError from '@/Components/Form/InputError.vue'

defineOptions({
    inheritAttrs: false,
})

/**
 * Component Props
 *
 * @property {String|Number} modelValue - The current value of the input.
 * @property {String} type - The HTML input type (default: 'text'). Often set to 'number'.
 * @property {String} label - The text label displayed above the input.
 * @property {String} error - An error message to display below the input if validation fails.
 * @property {Boolean} selectOnFocus - Whether to automatically select all text when the input is focused.
 */
const props = defineProps({
    modelValue: {
        type: [String, Number],
        default: '',
    },
    type: {
        type: String,
        default: 'text',
    },
    label: {
        type: String,
        default: '',
    },
    error: {
        type: String,
        default: '',
    },
    selectOnFocus: {
        type: Boolean,
        default: false,
    },
})

/**
 * Component Emits
 *
 * @event update:modelValue - Fired when the input value changes, either by direct typing or clicking the +/- buttons.
 */
defineEmits(['update:modelValue'])

const attrs = useAttrs()
const instance = getCurrentInstance()
const inputId = computed(() => {
    return attrs.id || `glass-fat-input-${instance?.uid}`
})

const errorId = computed(() => {
    return `${inputId.value}-error`
})
</script>

<template>
    <div class="w-full">
        <div
            class="glass-panel-light group focus-within:shadow-neon focus-within:ring-neon-green flex flex-col items-center rounded-[2rem] p-5 transition-all focus-within:ring-2"
        >
            <label
                v-if="label"
                :for="inputId"
                class="text-text-muted mb-2 text-center text-[10px] font-black tracking-widest uppercase"
            >
                {{ label }}
            </label>
            <input
                :id="inputId"
                :type="type"
                :value="modelValue"
                @input="$emit('update:modelValue', $event.target.value)"
                @focus="selectOnFocus ? $event.target.select() : null"
                :aria-invalid="!!error"
                :aria-describedby="errorId"
                class="glass-input-fat w-full"
                inputmode="decimal"
                v-bind="$attrs"
            />
            <div
                class="mt-3 flex w-full justify-between px-4 opacity-30 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100"
            >
                <button
                    v-press="{ haptic: 'tap' }"
                    type="button"
                    class="text-text-main active:bg-neon-green flex h-10 w-10 items-center justify-center rounded-xl border border-slate-100 bg-white text-2xl font-bold shadow-sm transition-transform hover:scale-110"
                    :aria-label="`Diminuer ${label || 'la valeur'}`"
                    @click="$emit('update:modelValue', Math.max(0, Number(modelValue) - 2.5))"
                >
                    -
                </button>
                <button
                    v-press="{ haptic: 'tap' }"
                    type="button"
                    class="text-text-main active:bg-neon-green flex h-10 w-10 items-center justify-center rounded-xl border border-slate-100 bg-white text-2xl font-bold shadow-sm transition-transform hover:scale-110"
                    :aria-label="`Augmenter ${label || 'la valeur'}`"
                    @click="$emit('update:modelValue', Number(modelValue) + 2.5)"
                >
                    +
                </button>
            </div>
        </div>

        <InputError :message="error" :id="errorId" />
    </div>
</template>

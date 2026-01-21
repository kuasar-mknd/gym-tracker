<script setup>
import { computed, useAttrs, getCurrentInstance } from 'vue'

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
    size: {
        type: String,
        default: 'md', // sm | md | lg | fat
    },
    variant: {
        type: String,
        default: 'default', // default | fat
    },
    selectOnFocus: {
        type: Boolean,
        default: false,
    },
})

defineEmits(['update:modelValue'])

const attrs = useAttrs()
const instance = getCurrentInstance()
const inputId = computed(() => {
    return attrs.id || `glass-input-${instance?.uid}`
})

const errorId = computed(() => {
    return `${inputId.value}-error`
})

const sizeClasses = {
    sm: 'min-h-[36px] text-sm rounded-lg',
    md: 'min-h-touch text-base rounded-xl',
    lg: 'min-h-[56px] text-lg rounded-2xl',
    fat: 'text-[4.5rem] leading-none rounded-[2rem] p-5',
}
</script>

<template>
    <div class="w-full">
        <label v-if="label" :for="inputId" class="font-display-label text-text-muted mb-2 block">
            {{ label }}
        </label>

        <!-- Fat numeric input for workout logging -->
        <div
            v-if="variant === 'fat'"
            class="glass-panel-light group focus-within:shadow-neon focus-within:ring-neon-green flex flex-col items-center rounded-[2rem] p-5 transition-all focus-within:ring-2"
        >
            <label
                v-if="label"
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
                class="glass-input-fat w-full"
                inputmode="decimal"
                v-bind="$attrs"
            />
            <div
                class="mt-3 flex w-full justify-between px-4 opacity-30 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100"
            >
                <button
                    type="button"
                    class="text-text-main active:bg-neon-green flex h-10 w-10 items-center justify-center rounded-xl border border-slate-100 bg-white text-2xl font-bold shadow-sm transition-transform hover:scale-110"
                    :aria-label="`Decrease ${label || 'value'}`"
                    @click="$emit('update:modelValue', Math.max(0, Number(modelValue) - 2.5))"
                >
                    -
                </button>
                <button
                    type="button"
                    class="text-text-main active:bg-neon-green flex h-10 w-10 items-center justify-center rounded-xl border border-slate-100 bg-white text-2xl font-bold shadow-sm transition-transform hover:scale-110"
                    :aria-label="`Increase ${label || 'value'}`"
                    @click="$emit('update:modelValue', Number(modelValue) + 2.5)"
                >
                    +
                </button>
            </div>
        </div>

        <!-- Standard input -->
        <template v-else>
            <input
                :id="inputId"
                :type="type"
                :value="modelValue"
                @input="$emit('update:modelValue', $event.target.value)"
                @focus="selectOnFocus ? $event.target.select() : null"
                :aria-invalid="!!error"
                :aria-describedby="error ? errorId : undefined"
                :class="[
                    'glass-input',
                    sizeClasses[size],
                    {
                        'border-red-500 focus:border-red-500 focus:ring-red-500/20': error,
                    },
                ]"
                v-bind="$attrs"
            />
            <p v-if="error" :id="errorId" class="mt-2 text-sm font-medium text-red-600">
                {{ error }}
            </p>
        </template>
    </div>
</template>

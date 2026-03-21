<script setup>
import { computed, useAttrs, getCurrentInstance } from 'vue'
import InputError from '@/Components/InputError.vue'

defineOptions({
    inheritAttrs: false,
})

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

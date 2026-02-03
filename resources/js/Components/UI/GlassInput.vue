<script setup>
import { computed, useAttrs, getCurrentInstance, ref } from 'vue'
import InputError from '@/Components/InputError.vue'

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

// Clear button logic
const hasClearButton = computed(() => {
    return ['text', 'search', 'email', 'url', 'tel'].includes(props.type)
})

const showClearButton = computed(() => {
    return (
        hasClearButton.value &&
        props.modelValue &&
        String(props.modelValue).length > 0 &&
        !attrs.disabled &&
        !attrs.readonly
    )
})

const isRequired = computed(() => {
    // Check for 'required' in attrs (Vue treats presence as empty string usually, or true if bound)
    return 'required' in attrs && attrs.required !== false
})

// Password toggle logic
const isPasswordVisible = ref(false)
const computedType = computed(() => {
    if (props.type === 'password' && isPasswordVisible.value) {
        return 'text'
    }
    return props.type
})
</script>

<template>
    <div class="w-full">
        <!-- Main Label (Hidden for 'fat' variant to avoid duplication) -->
        <label v-if="label && variant !== 'fat'" :for="inputId" class="font-display-label text-text-muted mb-2 block">
            {{ label }}
            <span v-if="isRequired" class="ml-0.5 text-red-500" aria-hidden="true">*</span>
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
        <div v-else class="relative">
            <input
                :id="inputId"
                :type="computedType"
                :value="modelValue"
                @input="$emit('update:modelValue', $event.target.value)"
                @focus="selectOnFocus ? $event.target.select() : null"
                :aria-invalid="!!error"
                :aria-describedby="error ? errorId : undefined"
                :class="[
                    'glass-input focus-visible:ring-neon-green/30 focus-visible:ring-2',
                    sizeClasses[size],
                    {
                        'border-red-500 focus:border-red-500 focus:ring-red-500/20': error,
                        'pr-10': hasClearButton || type === 'password', // Add padding for clear/toggle button
                    },
                ]"
                v-bind="$attrs"
            />

            <!-- Password Toggle Button -->
            <button
                v-if="type === 'password'"
                type="button"
                @click="isPasswordVisible = !isPasswordVisible"
                class="text-text-muted hover:text-text-main focus-visible:ring-electric-orange/50 absolute top-1/2 right-3 -translate-y-1/2 rounded-full p-1 transition-colors focus:outline-none focus-visible:ring-2"
                :aria-label="isPasswordVisible ? 'Masquer le mot de passe' : 'Afficher le mot de passe'"
            >
                <span class="material-symbols-outlined text-lg leading-none" aria-hidden="true">
                    {{ isPasswordVisible ? 'visibility_off' : 'visibility' }}
                </span>
            </button>

            <!-- Clear Button -->
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="scale-95 opacity-0"
                enter-to-class="scale-100 opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="scale-100 opacity-100"
                leave-to-class="scale-95 opacity-0"
            >
                <button
                    v-if="showClearButton"
                    type="button"
                    @click="$emit('update:modelValue', '')"
                    class="text-text-muted hover:text-text-main absolute top-1/2 right-3 -translate-y-1/2 rounded-full p-1 transition-all focus:bg-white/20 focus:ring-2 focus:ring-slate-300 focus:outline-none"
                    aria-label="Effacer le champ"
                >
                    <span class="material-symbols-outlined text-lg leading-none" aria-hidden="true">cancel</span>
                </button>
            </Transition>
        </div>

        <InputError :message="error" :id="errorId" />
    </div>
</template>

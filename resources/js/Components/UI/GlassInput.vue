<script setup>
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
})

defineEmits(['update:modelValue'])

const sizeClasses = {
    sm: 'min-h-[36px] text-sm rounded-lg',
    md: 'min-h-[44px] text-base rounded-xl',
    lg: 'min-h-[56px] text-lg rounded-2xl',
    fat: 'text-[4.5rem] leading-none rounded-[2rem] p-5',
}
</script>

<template>
    <div class="w-full">
        <label v-if="label" class="font-display-label mb-2 block text-text-muted">
            {{ label }}
        </label>

        <!-- Fat numeric input for workout logging -->
        <div
            v-if="variant === 'fat'"
            class="glass-panel-light group flex flex-col items-center rounded-[2rem] p-5 transition-all focus-within:shadow-neon focus-within:ring-2 focus-within:ring-neon-green"
        >
            <label
                v-if="label"
                class="mb-2 text-center text-[10px] font-black uppercase tracking-widest text-text-muted"
            >
                {{ label }}
            </label>
            <input
                :type="type"
                :value="modelValue"
                @input="$emit('update:modelValue', $event.target.value)"
                class="glass-input-fat w-full"
                inputmode="decimal"
                v-bind="$attrs"
            />
            <div class="mt-3 flex w-full justify-between px-4 opacity-30 transition-opacity group-hover:opacity-100">
                <button
                    type="button"
                    class="flex h-10 w-10 items-center justify-center rounded-xl border border-slate-100 bg-white text-2xl font-bold text-text-main shadow-sm transition-transform hover:scale-110 active:bg-neon-green"
                    @click="$emit('update:modelValue', Math.max(0, Number(modelValue) - 2.5))"
                >
                    -
                </button>
                <button
                    type="button"
                    class="flex h-10 w-10 items-center justify-center rounded-xl border border-slate-100 bg-white text-2xl font-bold text-text-main shadow-sm transition-transform hover:scale-110 active:bg-neon-green"
                    @click="$emit('update:modelValue', Number(modelValue) + 2.5)"
                >
                    +
                </button>
            </div>
        </div>

        <!-- Standard input -->
        <template v-else>
            <input
                :type="type"
                :value="modelValue"
                @input="$emit('update:modelValue', $event.target.value)"
                :class="[
                    'glass-input',
                    sizeClasses[size],
                    {
                        'border-red-500 focus:border-red-500 focus:ring-red-500/20': error,
                    },
                ]"
                v-bind="$attrs"
            />
            <p v-if="error" class="mt-2 text-sm font-medium text-red-600">
                {{ error }}
            </p>
        </template>
    </div>
</template>

<script setup>
import { computed } from 'vue'

const emit = defineEmits(['update:checked'])

const props = defineProps({
    checked: {
        type: [Array, Boolean],
        default: false,
    },
    value: {
        default: null,
    },
})

const proxyChecked = computed({
    get() {
        return props.checked
    },

    set(val) {
        emit('update:checked', val)
    },
})
</script>

<template>
    <div class="relative flex items-center justify-center">
        <input
            type="checkbox"
            :value="value"
            v-model="proxyChecked"
            class="peer checked:from-electric-orange checked:to-hot-pink focus-visible:ring-electric-orange/30 h-5 w-5 cursor-pointer appearance-none rounded-lg border border-slate-300 bg-slate-200 shadow-sm transition-all checked:border-transparent checked:bg-linear-to-br hover:scale-110 hover:border-slate-400 hover:bg-slate-300 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none active:scale-95 dark:border-slate-600 dark:bg-slate-700 dark:hover:border-slate-500 dark:hover:bg-slate-600"
        />
        <svg
            class="pointer-events-none absolute h-3.5 w-3.5 text-white opacity-0 transition-opacity duration-200 peer-checked:opacity-100"
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="4"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            <polyline points="20 6 9 17 4 12"></polyline>
        </svg>
    </div>
</template>

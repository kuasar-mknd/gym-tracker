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
    <div class="relative inline-flex items-center">
        <input
            type="checkbox"
            :value="value"
            v-model="proxyChecked"
            class="peer h-5 w-5 appearance-none rounded-lg border border-slate-200 bg-slate-50 shadow-sm backdrop-blur-md transition-all duration-300 ease-in-out checked:border-transparent checked:bg-gradient-to-br checked:from-electric-orange checked:to-hot-pink checked:ring-0 hover:scale-105 hover:bg-white/60 focus:outline-none focus:ring-2 focus:ring-electric-orange/50 focus:ring-offset-0 active:scale-95"
        />
        <svg
            class="pointer-events-none absolute left-1/2 top-1/2 h-3.5 w-3.5 -translate-x-1/2 -translate-y-1/2 text-white opacity-0 transition-opacity duration-200 peer-checked:opacity-100"
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="3"
            stroke-linecap="round"
            stroke-linejoin="round"
        >
            <polyline points="20 6 9 17 4 12"></polyline>
        </svg>
    </div>
</template>

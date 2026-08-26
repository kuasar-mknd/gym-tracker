<script setup>
import { computed } from 'vue'

const emit = defineEmits(['update:checked'])

const props = defineProps({
    checked: {
        type: [Array, Boolean],
        default: false,
    },
    value: {
        // Deliberately any: this is whatever the checkbox contributes to the
        // bound array, and callers pass ids, strings and objects alike.
        type: null,
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
            class="peer checked:from-accent-primary checked:to-accent-secondary focus-visible:ring-accent-primary/30 border-border bg-surface-sunken hover:border-border-strong hover:bg-text-muted h-5 w-5 cursor-pointer appearance-none rounded-lg border shadow-sm transition-all checked:border-transparent checked:bg-linear-to-br hover:scale-110 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none active:scale-95"
        />
        <svg
            class="text-text-on-accent pointer-events-none absolute h-3.5 w-3.5 opacity-0 transition-opacity duration-200 peer-checked:opacity-100"
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

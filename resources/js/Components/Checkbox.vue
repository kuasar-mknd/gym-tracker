<script setup>
import { computed, useAttrs } from 'vue'

defineOptions({
    inheritAttrs: false,
})

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

const attrs = useAttrs()

const rootAttrs = computed(() => {
    return {
        class: attrs.class,
        style: attrs.style,
    }
})

const inputAttrs = computed(() => {
    const { class: _, style: __, ...rest } = attrs
    return rest
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
    <div class="relative flex items-center justify-center" v-bind="rootAttrs">
        <input
            type="checkbox"
            :value="value"
            v-model="proxyChecked"
            v-bind="inputAttrs"
            class="peer checked:from-electric-orange checked:to-hot-pink focus:ring-electric-orange/30 h-5 w-5 appearance-none rounded-lg border border-slate-300 bg-white shadow-sm transition-all checked:border-transparent checked:bg-linear-to-br hover:scale-105 focus:ring-2 focus:ring-offset-0"
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
        >
            <polyline points="20 6 9 17 4 12"></polyline>
        </svg>
    </div>
</template>

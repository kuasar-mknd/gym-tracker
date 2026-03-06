<script setup>
defineProps({
    value: {
        type: String,
    },
})
</script>

<template>
    <label
        class="block w-fit rounded-2xl border border-white/20 bg-white/10 px-3 py-1.5 text-sm font-bold tracking-wide text-white/80 backdrop-blur-md transition-all duration-300 hover:scale-[1.02] hover:bg-white/20 hover:text-white hover:shadow-sm active:scale-95"
    >
        <span v-if="value">{{ value }}</span>
        <span v-else><slot /></span>
    </label>
</template>

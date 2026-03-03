<script setup>
defineProps({
    value: {
        type: String,
    },
})
</script>

<template>
    <label class="block rounded-2xl border border-white/20 bg-white/10 px-3 py-2 text-sm font-medium text-white/80 backdrop-blur-md transition-all duration-300 hover:scale-[1.02] hover:bg-white/20 active:scale-95">
        <span v-if="value">{{ value }}</span>
        <span v-else><slot /></span>
    </label>
</template>

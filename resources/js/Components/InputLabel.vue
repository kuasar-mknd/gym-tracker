<script setup>
defineProps({
    value: {
        type: String,
    },
})
</script>

<template>
    <label
        v-press
        class="mb-2 inline-flex cursor-pointer items-center rounded-2xl border border-white/20 bg-white/10 px-3 py-1.5 text-xs font-black tracking-widest text-slate-700 uppercase shadow-[0_4px_30px_rgba(0,0,0,0.1)] backdrop-blur-md transition-all duration-300 hover:scale-[1.02] hover:bg-white/20 hover:shadow-[0_4px_30px_rgba(0,0,0,0.2)] dark:text-white"
    >
        <span v-if="value">{{ value }}</span>
        <span v-else><slot /></span>
    </label>
</template>

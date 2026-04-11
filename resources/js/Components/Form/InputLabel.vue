<script setup>
defineProps({
    value: {
        type: String,
    },
})
</script>

<template>
    <label
        class="mb-2 inline-flex cursor-pointer items-center rounded-2xl border border-white/20 bg-white/10 px-3 py-1.5 text-xs font-black tracking-widest text-slate-700 uppercase shadow-sm backdrop-blur-md transition-all duration-300 hover:scale-[1.02] hover:bg-white/20 hover:shadow-md active:scale-95 dark:border-slate-700 dark:bg-slate-800/50 dark:text-white"
    >
        <span v-if="value">{{ value }}</span>
        <span v-else><slot /></span>
    </label>
</template>

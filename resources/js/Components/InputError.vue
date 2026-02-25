<script setup>
defineProps({
    message: {
        type: String,
    },
})
</script>

<template>
    <div
        v-show="message"
        role="alert"
        class="animate-fade-in mt-2 flex w-full cursor-default items-start gap-2 rounded-2xl border border-red-500/20 bg-red-500/10 px-4 py-3 backdrop-blur-md transition-all duration-300 hover:bg-red-500/20"
    >
        <span class="material-symbols-outlined mt-0.5 text-lg text-red-500 dark:text-red-400" aria-hidden="true">
            error
        </span>
        <p class="text-sm font-bold text-red-500 dark:text-red-400">
            {{ message }}
        </p>
    </div>
</template>

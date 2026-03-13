<script setup>
defineProps({
    count: {
        type: Number,
        default: 0,
    },
})
</script>

<template>
    <div class="group relative overflow-hidden rounded-3xl border border-white/20 bg-white/10 p-4 backdrop-blur-md transition-all duration-300 hover:-translate-y-1 hover:bg-white/20 hover:shadow-xl active:scale-95">
        <div class="flex items-center gap-3">
            <!-- Icon with Glow -->
            <div class="relative shrink-0">
                <div
                    class="absolute inset-0 rounded-full blur-lg transition-all duration-700"
                    :class="count > 0 ? 'bg-orange-500/50 group-hover:bg-orange-400/60' : 'bg-gray-500/20'"
                ></div>
                <div
                    class="relative flex h-12 w-12 items-center justify-center rounded-2xl border transition-all duration-300"
                    :class="
                        count > 0
                            ? 'border-orange-500/30 bg-linear-to-br from-orange-400/20 to-red-500/20'
                            : 'border-white/10 bg-white/5'
                    "
                >
                    <div class="animate-pulse-slow relative z-10 text-2xl" v-if="count > 0">🔥</div>
                    <div class="text-2xl opacity-50 grayscale" v-else>🔥</div>
                </div>
            </div>

            <!-- Content -->
            <div>
                <p class="text-xs font-bold tracking-wider text-white/40 uppercase">Série actuelle</p>
                <div class="flex items-baseline gap-1">
                    <span class="text-2xl font-bold text-white">{{ count }}</span>
                    <span class="text-xs text-white/60">jours</span>
                </div>
            </div>
        </div>

        <!-- Background Effect -->
        <div
            class="absolute -right-4 -bottom-4 h-24 w-24 rounded-full bg-linear-to-tl from-orange-500/10 to-transparent blur-xl transition-opacity duration-300"
            :class="count > 0 ? 'opacity-100' : 'opacity-0'"
        ></div>
    </div>
</template>

<style scoped>
.animate-pulse-slow {
    animation: pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}

@keyframes pulse {
    0%,
    100% {
        opacity: 1;
        transform: scale(1);
    }
    50% {
        opacity: 0.8;
        transform: scale(0.95);
    }
}
</style>

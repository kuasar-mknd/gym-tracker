<script setup>
import { ref, watch, onMounted } from 'vue'
import { usePage, router } from '@inertiajs/vue3'

const show = ref(false)
const achievement = ref(null)
const notificationId = ref(null)

const page = usePage()

const checkAchievement = () => {
    if (page.props.is_testing) return
    const latest = page.props.auth.user?.latest_achievement
    if (latest && latest.data) {
        // Avoid showing same achievement twice in same session if not cleared
        if (notificationId.value !== latest.id) {
            achievement.value = latest.data
            notificationId.value = latest.id
            show.value = true
        }
    }
}

watch(
    () => page.props.auth.user?.latest_achievement,
    () => {
        checkAchievement()
    },
    { deep: true },
)

onMounted(() => {
    checkAchievement()
})

const close = () => {
    show.value = false
    if (notificationId.value) {
        router.post(
            route('notifications.mark-as-read', { id: notificationId.value }),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    notificationId.value = null
                    achievement.value = null
                },
            },
        )
    }
}
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center px-4">
        <!-- Backdrop -->
        <div class="animate-fade-in absolute inset-0 bg-black/80 backdrop-blur-sm" @click="close"></div>

        <!-- Modal -->
        <div
            class="animate-bounce-in relative w-full max-w-sm rounded-3xl border border-white/20 bg-white/10 p-6 text-center shadow-2xl backdrop-blur-md transition-all duration-300 hover:bg-white/20 dark:bg-black/40"
            role="dialog"
            aria-modal="true"
            aria-labelledby="achievement-title"
            aria-describedby="achievement-description"
        >
            <!-- Glow Effect -->
            <div
                class="to-accent-secondary/20 from-accent-primary/20 pointer-events-none absolute inset-0 rounded-3xl bg-linear-to-br via-transparent opacity-50"
            ></div>

            <div class="relative z-10 flex flex-col items-center">
                <!-- Confetti/Burst Animation Background (CSS only) -->
                <div class="pointer-events-none absolute top-0 left-0 h-full w-full overflow-hidden">
                    <div
                        class="bg-accent-primary/20 absolute top-1/2 left-1/2 h-64 w-64 -translate-x-1/2 -translate-y-1/2 animate-pulse rounded-full blur-[60px]"
                    ></div>
                </div>

                <!-- Icon -->
                <div class="relative mb-4" aria-hidden="true">
                    <div
                        class="animate-wobble flex h-24 w-24 items-center justify-center rounded-3xl border border-white/20 bg-white/10 text-6xl shadow-lg backdrop-blur-md"
                    >
                        {{ achievement.icon }}
                    </div>
                </div>

                <!-- Text -->
                <h2 class="text-text-main mb-1 text-xl font-bold dark:text-white" id="achievement-title">Badge Débloqué !</h2>
                <h3 class="text-accent-primary mb-3 text-lg font-bold">{{ achievement.name }}</h3>
                <p class="text-text-muted mb-6 text-sm dark:text-white/70" id="achievement-description">
                    {{
                        achievement.message?.replace('Nouveau badge débloqué : ' + achievement.name + ' !', '') ||
                        'Félicitations pour cet exploit !'
                    }}
                </p>

                <!-- Action -->
                <button
                    v-press
                    @click="close"
                    class="w-full rounded-2xl border border-white/20 bg-white/10 py-3 font-bold text-slate-800 shadow-lg backdrop-blur-md transition-all hover:bg-white/20 active:scale-95 dark:text-white"
                >
                    Génial ! 🤩
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
@keyframes bounce-in {
    0% {
        transform: scale(0.3);
        opacity: 0;
    }
    50% {
        transform: scale(1.05);
    }
    70% {
        transform: scale(0.9);
    }
    100% {
        transform: scale(1);
        opacity: 1;
    }
}
.animate-bounce-in {
    animation: bounce-in 0.6s cubic-bezier(0.21, 1.02, 0.73, 1) forwards;
}

@keyframes wobble {
    0%,
    100% {
        transform: rotate(0deg);
    }
    15% {
        transform: rotate(-5deg);
    }
    30% {
        transform: rotate(3deg);
    }
    45% {
        transform: rotate(-3deg);
    }
    60% {
        transform: rotate(2deg);
    }
    75% {
        transform: rotate(-1deg);
    }
}
.animate-wobble {
    animation: wobble 1s ease-in-out infinite;
    animation-delay: 0.5s;
}

@keyframes fade-in {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}
.animate-fade-in {
    animation: fade-in 0.3s ease-out forwards;
}
</style>

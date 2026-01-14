<script setup>
import { ref, watch, onMounted } from 'vue'
import { usePage, router } from '@inertiajs/vue3'

const show = ref(false)
const achievement = ref(null)
const notificationId = ref(null)

const page = usePage()

const checkAchievement = () => {
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
            route('notifications.mark-as-read', notificationId.value),
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
        <div class="absolute inset-0 animate-fade-in bg-black/80 backdrop-blur-sm" @click="close"></div>

        <!-- Modal -->
        <div
            class="animate-bounce-in relative w-full max-w-sm overflow-hidden rounded-3xl bg-glass-strong p-1 shadow-2xl ring-1 ring-white/10"
        >
            <!-- Glow Effect -->
            <div
                class="to-accent-secondary/20 absolute inset-0 bg-gradient-to-br from-accent-primary/20 via-transparent opacity-50"
            ></div>

            <div class="relative flex flex-col items-center rounded-[20px] bg-gray-900/50 p-6 text-center">
                <!-- Confetti/Burst Animation Background (CSS only) -->
                <div class="pointer-events-none absolute left-0 top-0 h-full w-full overflow-hidden">
                    <div
                        class="absolute left-1/2 top-1/2 h-64 w-64 -translate-x-1/2 -translate-y-1/2 animate-pulse rounded-full bg-accent-primary/20 blur-[60px]"
                    ></div>
                </div>

                <!-- Icon -->
                <div class="relative mb-4">
                    <div
                        class="animate-wobble flex h-24 w-24 items-center justify-center rounded-3xl border border-white/10 bg-gradient-to-br from-white/10 to-white/5 text-6xl shadow-lg"
                    >
                        {{ achievement.icon }}
                    </div>
                </div>

                <!-- Text -->
                <h2 class="mb-1 text-xl font-bold text-white">Badge Débloqué !</h2>
                <h3 class="mb-3 text-lg font-bold text-accent-primary">{{ achievement.name }}</h3>
                <p class="mb-6 text-sm text-white/70">
                    {{
                        achievement.message?.replace('Nouveau badge débloqué : ' + achievement.name + ' !', '') ||
                        'Félicitations pour cet exploit !'
                    }}
                </p>

                <!-- Action -->
                <button
                    @click="close"
                    class="hover:bg-accent-primary-hover w-full rounded-xl bg-accent-primary py-3 font-bold text-white shadow-lg shadow-accent-primary/25 transition-all active:scale-95"
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

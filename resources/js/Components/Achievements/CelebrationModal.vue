<script setup>
import { ref, watch, onMounted } from 'vue'
import { usePage, router } from '@inertiajs/vue3'

const show = ref(false)
const achievement = ref(null)
const notificationId = ref(null)
const dialog = ref(null)

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

/**
 * This one deliberately does not route through Components/UI/Modal: its
 * overlay and card are a different design on purpose — a near-black backdrop,
 * a dark glass panel, a bounce-in — and Modal's own chrome would stack a
 * second background and border on top of them.
 *
 * What it does need is the part Modal gets for free. It had the ARIA
 * attributes of a dialog without being one: nothing kept Tab inside it,
 * Escape did nothing, and the page behind stayed reachable — on an overlay
 * that covers the whole screen. showModal() supplies all of that natively.
 */
watch(
    show,
    (isVisible) => {
        if (isVisible) {
            dialog.value?.showModal()
        } else if (dialog.value?.open) {
            dialog.value.close()
        }
    },
    // post: the panel inside the dialog is rendered by v-if on the same flag,
    // so opening has to wait for that render or the dialog opens empty.
    { flush: 'post' },
)

const close = () => {
    // Re-entrant: closing the dialog fires its own close event, and Escape
    // closes it before we ever get here. Without this the notification would
    // be marked as read twice.
    if (!show.value) {
        return
    }

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
    <Teleport to="body">
        <dialog
            ref="dialog"
            class="m-0 h-full max-h-full w-full max-w-full bg-transparent p-0 backdrop:bg-transparent"
            aria-labelledby="achievement-title"
            aria-describedby="achievement-description"
            @close="close"
        >
            <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center px-4">
                <!-- Backdrop -->
                <div class="animate-fade-in absolute inset-0 bg-black/80 backdrop-blur-sm" @click="close"></div>

                <!-- Modal. No role or aria-modal here: the <dialog> above is the
                     dialog, and nesting a second one would announce two. -->
                <div
                    class="animate-bounce-in border-surface-card/20 bg-surface-card/10 hover:bg-surface-card/20 relative w-full max-w-sm rounded-3xl border p-6 text-center shadow-2xl backdrop-blur-md transition-all duration-300"
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
                                class="animate-wobble border-surface-card/20 bg-surface-card/10 flex h-24 w-24 items-center justify-center rounded-3xl border text-6xl shadow-lg backdrop-blur-md"
                            >
                                {{ achievement.icon }}
                            </div>
                        </div>

                        <!-- Text -->
                        <h2 class="text-text-main mb-1 text-xl font-bold" id="achievement-title">Badge Débloqué !</h2>
                        <h3 class="text-accent-primary mb-3 text-lg font-bold">{{ achievement.name }}</h3>
                        <p class="text-text-muted mb-6 text-sm" id="achievement-description">
                            {{
                                achievement.message?.replace(
                                    'Nouveau badge débloqué : ' + achievement.name + ' !',
                                    '',
                                ) || 'Félicitations pour cet exploit !'
                            }}
                        </p>

                        <!-- Action -->
                        <button
                            v-press
                            type="button"
                            @click="close"
                            dusk="celebration-dismiss"
                            class="border-surface-card/20 bg-surface-card/10 text-text-main hover:bg-surface-card/20 w-full rounded-2xl border py-3 font-bold shadow-lg backdrop-blur-md transition-all active:scale-95"
                        >
                            Génial ! 🤩
                        </button>
                    </div>
                </div>
            </div>
        </dialog>
    </Teleport>
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

<script setup>
/**
 * RestTimerOverlay.vue
 *
 * A sticky bottom overlay that shows the rest timer countdown.
 * Includes controls for +30s/-30s and stop.
 * Uses haptic feedback for mobile interactivity.
 */
import { useRestTimer } from '@/composables/useRestTimer'
import { vibrate } from '@/composables/useHaptics'

const { isRunning, formattedTime, progress, addTime, stop } = useRestTimer()

function handleStop() {
    vibrate('tap')
    stop()
}

function handleAddTime(seconds) {
    vibrate('tap')
    addTime(seconds)
}
</script>

<template>
    <Transition
        enter-active-class="transition-all duration-300 ease-out"
        enter-from-class="translate-y-full opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition-all duration-200 ease-in"
        leave-from-class="translate-y-0 opacity-100"
        leave-to-class="translate-y-full opacity-0"
    >
        <div
            v-if="isRunning"
            class="fixed right-0 bottom-20 left-0 z-50 px-4 sm:bottom-4"
            :style="{ paddingBottom: 'var(--safe-area-bottom)' }"
        >
            <div
                class="bg-pearl-white/95 mx-auto flex max-w-lg items-center justify-between gap-4 rounded-2xl border border-white/60 p-4 shadow-2xl backdrop-blur-xl"
            >
                <!-- Timer Display -->
                <div class="flex items-center gap-3">
                    <div
                        class="from-electric-orange to-hot-pink relative flex h-14 w-14 items-center justify-center rounded-xl bg-linear-to-br shadow-lg"
                    >
                        <span class="material-symbols-outlined text-3xl text-white">timer</span>
                        <!-- Progress ring -->
                        <svg class="absolute inset-0 -rotate-90" viewBox="0 0 56 56">
                            <circle
                                cx="28"
                                cy="28"
                                r="24"
                                fill="none"
                                stroke="rgba(255,255,255,0.3)"
                                stroke-width="4"
                            />
                            <circle
                                cx="28"
                                cy="28"
                                r="24"
                                fill="none"
                                stroke="white"
                                stroke-width="4"
                                stroke-linecap="round"
                                :stroke-dasharray="150.8"
                                :stroke-dashoffset="150.8 * (1 - progress / 100)"
                                class="transition-all duration-200"
                            />
                        </svg>
                    </div>
                    <div>
                        <p class="text-text-muted text-[10px] font-bold tracking-widest uppercase">Repos</p>
                        <p class="font-display text-text-main text-3xl font-black tracking-tight tabular-nums">
                            {{ formattedTime }}
                        </p>
                    </div>
                </div>

                <!-- Controls -->
                <div class="flex items-center gap-2">
                    <button
                        @click="handleAddTime(-30)"
                        class="text-text-muted hover:text-electric-orange flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white transition-all active:scale-95"
                        title="-30s"
                    >
                        <span class="text-sm font-bold">-30</span>
                    </button>
                    <button
                        @click="handleAddTime(30)"
                        class="text-text-muted hover:text-electric-orange flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white transition-all active:scale-95"
                        title="+30s"
                    >
                        <span class="text-sm font-bold">+30</span>
                    </button>
                    <button
                        @click="handleStop"
                        class="flex h-10 w-10 items-center justify-center rounded-xl bg-red-500 text-white shadow-lg transition-all hover:bg-red-600 active:scale-95"
                        title="Stop"
                    >
                        <span class="material-symbols-outlined text-xl">stop</span>
                    </button>
                </div>
            </div>
        </div>
    </Transition>
</template>

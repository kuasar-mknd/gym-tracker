<script setup>
/**
 * La pastille qui descend du haut de l'écran quand on tire la page vers le
 * bas : une flèche qui se retourne passé le seuil, puis une roue le temps du
 * rechargement. Le geste lui-même vit dans `usePullToRefresh`.
 */
defineProps({
    distance: { type: Number, default: 0 },
    enCours: { type: Boolean, default: false },
})
</script>

<template>
    <div
        class="pointer-events-none fixed top-0 left-0 z-50 flex w-full justify-center transition-transform duration-200 ease-out"
        :style="{ transform: `translateY(${Math.min(distance, 150)}px)` }"
    >
        <div
            v-if="distance > 0 || enCours"
            class="border-border bg-surface-card/90 mt-4 rounded-full border p-3 shadow-lg backdrop-blur-md"
        >
            <svg
                v-if="enCours"
                class="text-accent-primary-deep h-6 w-6 animate-spin"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
            >
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path
                    class="opacity-75"
                    fill="currentColor"
                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                ></path>
            </svg>
            <span
                v-else
                class="material-symbols-outlined text-accent-primary-deep transition-transform duration-200"
                :style="{ transform: `rotate(${distance > 100 ? 180 : 0}deg)` }"
                aria-hidden="true"
            >
                arrow_downward
            </span>
        </div>
    </div>
</template>

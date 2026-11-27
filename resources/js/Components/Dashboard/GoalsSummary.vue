<script setup>
import { Link } from '@inertiajs/vue3'
import GlassCard from '@/Components/UI/GlassCard.vue'
import IndisponibleHorsLigne from '@/Components/UI/IndisponibleHorsLigne.vue'
import { pourcentage, progressionAffichee } from '@/Utils/nombre'

defineProps({
    activeGoals: { type: Array, required: true },
    /**
     * Les objectifs manquent parce que la page est une copie hors ligne, qui
     * ne garde jamais rien d'eux (#2048) : la carte le dit, plutôt que de
     * disparaître comme pour un compte sans objectif. Sans lien vers les
     * objectifs, qu'aucune copie ne garde non plus.
     */
    indisponible: { type: Boolean, default: false },
})
</script>

<template>
    <!-- Goals Summary (Compact) -->
    <section v-if="indisponible" class="stagger-5 animate-slide-up" dusk="objectifs-indisponibles">
        <h3 class="titre-plat mb-4 px-1">Objectifs en cours</h3>
        <IndisponibleHorsLigne texte="Objectifs indisponibles hors ligne" />
    </section>
    <section v-else-if="activeGoals.length > 0" class="stagger-5 animate-slide-up">
        <div class="mb-4 flex items-center justify-between px-1">
            <h3 class="titre-plat">Objectifs en cours</h3>
            <Link
                :href="route('goals.index')"
                class="text-text-muted hover:text-text-main focus-visible:ring-accent-primary rounded-md text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
            >
                Voir tout
            </Link>
        </div>

        <div class="space-y-3">
            <Link
                v-for="goal in activeGoals.slice(0, 2)"
                :key="goal.id"
                :href="route('goals.index')"
                class="focus-visible:ring-accent-primary rounded-carte block focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
            >
                <GlassCard variant="fin" :hover="true" padding="p-4">
                    <div class="mb-2 flex items-center justify-between">
                        <span class="text-text-main line-clamp-1 text-sm font-medium">{{ goal.title }}</span>
                        <span class="text-text-main text-xs font-medium tabular-nums">{{
                            pourcentage(progressionAffichee(goal), 0)
                        }}</span>
                    </div>
                    <div
                        class="bg-surface-sunken h-2 w-full overflow-hidden rounded-full"
                        role="progressbar"
                        :aria-valuenow="goal.progress_pct"
                        aria-valuemin="0"
                        aria-valuemax="100"
                    >
                        <div
                            class="bg-accent-primary h-full rounded-full transition duration-1000"
                            :style="{ width: goal.progress_pct + '%' }"
                        ></div>
                    </div>
                </GlassCard>
            </Link>
        </div>
    </section>
</template>

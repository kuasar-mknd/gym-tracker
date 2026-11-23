<script setup>
import { computed, inject } from 'vue'
import { Link } from '@inertiajs/vue3'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { CLE_DE_LA_SEANCE_EN_COURS } from '@/Utils/cleDeLaSeanceEnCours'

defineProps({
    processing: { type: Boolean, default: false },
})

/*
 * Démarrer reste ouvert sur une copie hors ligne (#2049) : la séance démarre
 * par la file, sans rien tirer de la copie (`useDemarrageDUneSeance`).
 */
const emit = defineEmits(['startWorkout'])

/** La séance en cours de l'appareil que le layout offre : « Reprendre » la remplace alors. */
const seanceEnCours = inject(CLE_DE_LA_SEANCE_EN_COURS, null)
const demarrageEstPropose = computed(() => (seanceEnCours?.value ?? null) === null)
</script>

<template>
    <section class="stagger-2 animate-slide-up grid gap-3" :class="demarrageEstPropose ? 'grid-cols-2' : 'grid-cols-1'">
        <!-- Démarrer une séance : l'action principale, seul aplat orange de l'écran. -->
        <button
            v-if="demarrageEstPropose"
            v-press
            @click="emit('startWorkout')"
            :disabled="processing"
            :aria-busy="processing"
            id="start-workout-button"
            dusk="start-workout-button"
            class="accent-fill verre-plein rounded-carte focus-visible:ring-accent-primary flex min-h-28 flex-col items-start justify-between p-4 text-left text-base leading-tight font-medium focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
            :class="{ 'cursor-wait': processing }"
        >
            <span class="bg-surface-card/20 rounded-icone flex size-10 items-center justify-center">
                <GlassIcon v-if="processing" name="autorenew" size="lg" class="animate-spin" />
                <GlassIcon v-else name="fitness_center" size="lg" />
            </span>
            Démarrer une séance
        </button>

        <!-- Modèles -->
        <Link
            v-press
            :href="route('templates.index')"
            class="verre-regulier rounded-carte text-text-main focus-visible:ring-accent-primary flex min-h-28 flex-col items-start justify-between p-4 text-base leading-tight font-medium focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
        >
            <span class="bg-text-main/10 rounded-icone flex size-10 items-center justify-center">
                <GlassIcon name="assignment_add" size="lg" />
            </span>
            Mes modèles
        </Link>
    </section>
</template>

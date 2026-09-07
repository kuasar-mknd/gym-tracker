<script>
export const TAILLES = ['md', 'lg']
export const SENS = ['hausse', 'baisse', 'stable']
</script>

<script setup>
/**
 * Un chiffre et son libellé, dans une carte : total de séances, poids du jour,
 * volume du mois. Une seule disposition pour ce rôle, dans une grille de deux
 * ou trois colonnes selon la page.
 */
import GlassCard from '@/Components/UI/GlassCard.vue'

defineProps({
    valeur: {
        type: [String, Number],
        required: true,
    },
    unite: {
        type: String,
        default: null,
    },
    libelle: {
        type: String,
        required: true,
    },
    /** `{ texte: '+0,3 kg', sens: 'hausse' }` ; le sens ne colore rien par lui-même. */
    tendance: {
        type: Object,
        default: null,
        validator: (valeur) => 'texte' in valeur && SENS.includes(valeur.sens),
    },
    taille: {
        type: String,
        default: 'md',
        validator: (valeur) => TAILLES.includes(valeur),
    },
    ton: {
        type: String,
        default: 'text-text-main',
    },
})

const ICONES = { hausse: 'trending_up', baisse: 'trending_down', stable: 'trending_flat' }
</script>

<template>
    <GlassCard padding="p-4" class="flex flex-col justify-between gap-2">
        <p class="sur-titre text-text-muted">{{ libelle }}</p>
        <p
            class="font-display flex items-baseline gap-1 font-black tabular-nums"
            :class="[ton, taille === 'lg' ? 'text-4xl' : 'text-2xl']"
        >
            {{ valeur }}
            <span v-if="unite" class="text-text-muted text-sm font-bold">{{ unite }}</span>
        </p>
        <p v-if="tendance" class="text-text-muted flex items-center gap-1 text-xs font-bold">
            <span class="material-symbols-outlined text-base" aria-hidden="true">{{ ICONES[tendance.sens] }}</span>
            {{ tendance.texte }}
        </p>
    </GlassCard>
</template>

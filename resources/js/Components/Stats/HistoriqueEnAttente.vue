<script setup>
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'

/**
 * Ce que montre la fiche d'un exercice tant que son historique, prop différée
 * (#2351), n'est pas arrivé : la place des cartes de graphiques (quatre pour une force, une pour
 * le cardio et le temps), qui pulsent, plutôt qu'un « pas assez de données » qui serait faux.
 */

defineProps({
    cartes: { type: Number, default: 4 },
})
</script>

<template>
    <div class="grid grid-cols-1 gap-6 md:grid-cols-2" data-historique-en-attente>
        <GlassCard v-for="rang in cartes" :key="rang" class="animate-pulse">
            <GlassSkeleton width="120px" height="1.5rem" class="mb-4" />
            <GlassSkeleton width="100%" height="16rem" class="rounded-xl" />
        </GlassCard>
    </div>
</template>

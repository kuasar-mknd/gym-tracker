<script setup>
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'

/**
 * Ce que montre la fiche d'un exercice tant que son historique, prop différée
 * (#2351), n'est pas arrivé : la place des treize cartes de graphiques (dix en grille, trois pleine largeur), qui
 * pulsent, plutôt qu'un « pas assez de données » qui serait faux.
 */
</script>

<template>
    <div class="space-y-6" data-historique-en-attente>
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
            <GlassCard v-for="rang in 10" :key="rang" class="animate-pulse">
                <GlassSkeleton width="120px" height="1.5rem" class="mb-4" />
                <GlassSkeleton width="100%" height="16rem" class="rounded-xl" />
            </GlassCard>
        </div>
        <GlassCard v-for="rang in 3" :key="`seule-${rang}`" class="animate-pulse">
            <GlassSkeleton width="160px" height="1.5rem" class="mb-4" />
            <GlassSkeleton width="100%" height="16rem" class="rounded-xl" />
        </GlassCard>
    </div>
</template>

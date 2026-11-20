<script setup>
import { Link } from '@inertiajs/vue3'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { ONGLETS, ongletDeLaRoute } from '@/Utils/ongletsDeLaBarre'

/*
 * Quatre onglets, et aucun bouton : la séance en cours se retrouve dans
 * l'accessoire au-dessus de la barre (`ActiveWorkoutBanner`), et « Démarrer »
 * vit sur l'Accueil et sur Séances. Ajouter un exercice vit dans la barre
 * d'outils de la séance (`ActionsDeLaSeance`).
 */
const ongletAllume = () => ongletDeLaRoute(route().current())?.cle ?? null
</script>

<template>
    <nav class="glass-nav" aria-label="Navigation principale">
        <Link
            v-for="onglet in ONGLETS"
            :key="onglet.cle"
            v-press
            :href="route(onglet.route)"
            :class="[
                'glass-nav-item focus-visible:ring-accent-primary transition focus-visible:ring-2 focus-visible:outline-none',
                { active: ongletAllume() === onglet.cle },
            ]"
            :aria-current="ongletAllume() === onglet.cle ? 'page' : undefined"
            :dusk="'nav-' + onglet.route.split('.')[0]"
        >
            <GlassIcon :name="onglet.icone" size="md" :fill="ongletAllume() === onglet.cle" />
            <span>{{ onglet.nom }}</span>
        </Link>
    </nav>
</template>

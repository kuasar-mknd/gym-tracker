<script setup>
import { computed } from 'vue'
import LogoApple from '@/Components/Marques/LogoApple.vue'
import LogoGitHub from '@/Components/Marques/LogoGitHub.vue'
import LogoGoogle from '@/Components/Marques/LogoGoogle.vue'

/**
 * Les boutons « continuer avec Google, GitHub ou Apple » des pages de connexion
 * et d'inscription.
 *
 * Un bouton n'apparaît que si le serveur déclare son fournisseur utilisable
 * (`social_login_enabled`), et la séparation « Ou … » seulement s'il en reste
 * un. Les logos sont dessinés par l'application (`Components/Marques`) : ils
 * venaient d'un site d'icônes tiers, que chaque visite renseignait sur la
 * personne avant qu'elle ait choisi quoi que ce soit, et que la CSP devait
 * laisser entrer (#2051). Chaque bouton porte un nom, « Continuer avec
 * Google », puisque le logo seul ne se lit pas.
 */
const props = defineProps({
    /** Le début du nom de chaque bouton : « Continuer avec », « S'inscrire avec ». */
    verbe: { type: String, required: true },
})

/**
 * Dans l'ordre d'affichage. Google en tête, ce qui le rend au moins aussi
 * visible que ses voisins, comme ses règles le demandent ; elles n'imposent
 * pas d'ordre. `cle` est le segment de `social.redirect`. Le bouton d'Apple n'a ni fond ni
 * bordure : le carré noir de son logo le remplit, et `overflow-hidden` le rogne
 * à la forme de ses voisins, le masque que demandent les règles d'Apple
 * (`Components/Marques/LogoApple.vue`).
 */
const FOURNISSEURS = [
    {
        cle: 'google',
        nom: 'Google',
        logo: LogoGoogle,
        bouton: 'border-surface-sunken bg-surface-card border',
        tailleDuLogo: 'h-6 w-6',
    },
    {
        cle: 'github',
        nom: 'GitHub',
        logo: LogoGitHub,
        bouton: 'border-text-main bg-text-main border',
        tailleDuLogo: 'h-6 w-6',
    },
    {
        cle: 'apple',
        nom: 'Apple',
        logo: LogoApple,
        bouton: 'overflow-hidden',
        tailleDuLogo: 'h-12 w-12',
    },
]

/**
 * Les fournisseurs que le serveur déclare utilisables. Sans déclaration, aucun :
 * un bouton qui répondrait par une erreur passerait pour une porte d'entrée.
 *
 * @param {Record<string, boolean> | undefined} declares
 * @returns {typeof FOURNISSEURS}
 */
const fournisseursOfferts = (declares) => FOURNISSEURS.filter(({ cle }) => Boolean(declares?.[cle]))

/** « Ou continuer avec », « Ou s'inscrire avec ». */
const separation = computed(() => `Ou ${props.verbe.charAt(0).toLowerCase()}${props.verbe.slice(1)}`)
</script>

<template>
    <div v-if="fournisseursOfferts($page.props.social_login_enabled).length > 0">
        <div class="my-6 flex items-center justify-center gap-4 text-sm">
            <div class="bg-border h-px flex-1"></div>
            <span
                class="text-text-muted border-surface-card/50 bg-surface-card/60 rounded-full border px-4 py-1 font-medium backdrop-blur-md"
            >
                {{ separation }}
            </span>
            <div class="bg-border h-px flex-1"></div>
        </div>

        <div class="flex justify-center gap-4">
            <a
                v-for="fournisseur in fournisseursOfferts($page.props.social_login_enabled)"
                :key="fournisseur.cle"
                :href="route('social.redirect', fournisseur.cle)"
                :class="fournisseur.bouton"
                class="flex h-12 w-12 items-center justify-center rounded-xl shadow-sm transition hover:scale-105 hover:shadow-md active:scale-95"
                :aria-label="`${verbe} ${fournisseur.nom}`"
            >
                <component :is="fournisseur.logo" :class="fournisseur.tailleDuLogo" />
            </a>
        </div>
    </div>
</template>

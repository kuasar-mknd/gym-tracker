<script setup>
import { Link } from '@inertiajs/vue3'

defineProps({
    /**
     * Sans adresse, l'entrée est un bouton qui transmet son clic. La
     * déconnexion en a besoin : elle détache l'appareil du compte avant de
     * partir, ce qu'un lien Inertia ne sait pas attendre (#1926).
     */
    href: {
        type: String,
        default: null,
    },
})
</script>

<!--
    Le texte etait `text-text-on-dark-accent/80`, sur un fond `bg-surface-card/10` : ecrit pour un
    panneau sombre et translucide, alors que le menu flotte au-dessus du fond
    clair de l'application. Rendre le panneau opaque ne suffisait donc pas —
    du blanc a 80 % sur du blanc reste du blanc (#1314).

    La couleur vient maintenant de `--text-main`, qui suit le theme, et le
    survol d'un gris franc plutot que d'un voile blanc. La tuile interne
    (`border-glass-border/20 bg-surface-card/10 backdrop-blur-md`) part avec : un verre
    depoli pose sur un verre depoli n'ajoute que du bruit.

    Le bouton porte les memes classes que le lien : c'est ce qu'Inertia
    rendait pour `<Link as="button">`, que la deconnexion employait avant.
-->
<template>
    <component
        :is="href ? Link : 'button'"
        v-press
        :href="href ?? undefined"
        :type="href ? undefined : 'button'"
        class="text-text-main focus-visible:ring-accent-primary hover:bg-surface-sunken focus-visible:bg-surface-sunken mx-2 my-1 block rounded-2xl px-4 py-2.5 text-start text-sm font-medium transition duration-200 hover:scale-[1.02] focus-visible:ring-2 focus-visible:outline-none"
    >
        <slot />
    </component>
</template>

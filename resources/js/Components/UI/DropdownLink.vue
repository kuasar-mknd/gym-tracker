<script setup>
import { Link } from '@inertiajs/vue3'

defineProps({
    href: {
        type: String,
        required: true,
    },
})
</script>

<!--
    Le texte etait `text-white/80`, sur un fond `bg-white/10` : ecrit pour un
    panneau sombre et translucide, alors que le menu flotte au-dessus du fond
    clair de l'application. Rendre le panneau opaque ne suffisait donc pas —
    du blanc a 80 % sur du blanc reste du blanc (#1314).

    La couleur vient maintenant de `--text-main`, qui suit le theme, et le
    survol d'un gris franc plutot que d'un voile blanc. La tuile interne
    (`border-white/20 bg-white/10 backdrop-blur-md`) part avec : un verre
    depoli pose sur un verre depoli n'ajoute que du bruit.
-->
<template>
    <Link
        v-press
        :href="href"
        class="text-text-main focus-visible:ring-electric-orange mx-2 my-1 block rounded-2xl px-4 py-2.5 text-start text-sm font-medium transition-all duration-200 hover:scale-[1.02] hover:bg-slate-100 focus-visible:bg-slate-100 focus-visible:ring-2 focus-visible:outline-none dark:text-slate-100 dark:hover:bg-slate-700/60 dark:focus-visible:bg-slate-700/60"
    >
        <slot />
    </Link>
</template>

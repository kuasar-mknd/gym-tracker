<script>
export const TONS = ['neutre', 'danger', 'accent', 'info']
</script>

<script setup>
import { computed } from 'vue'

/**
 * Une action qui tient dans une icône : modifier, supprimer, fermer.
 *
 * Vingt-trois de ces boutons étaient écrits à la main, et ils ne se
 * ressemblaient pas. Sept façons de dire leur taille — `size-10`, `h-8 w-8`,
 * `p-1`, `p-2`, `size-6`, `min-h-touch`, et quatre fois rien du tout —, deux
 * façons d'agrandir la zone tactile, et une couleur au repos qui allait du gris
 * au rouge plein selon l'écran.
 *
 * Le désaccord n'est pas qu'esthétique. Un `p-1` autour d'une icône de 18 px
 * fait une cible de 26 px ; le minimum retenu par les WCAG (2.5.8) et par la
 * charte de ce dépôt est de 44. Cinq écrans étaient donc en dessous, et deux
 * autres n'avaient aucune taille du tout.
 *
 * Le composant tranche : la CIBLE fait toujours 44 px, même quand la vignette
 * visible est plus petite — c'est à cela que sert le pseudo-élément de
 * `compact`, qui déborde sans pousser ses voisins.
 */
const props = defineProps({
    /** Le nom de ligature Material Symbols. */
    icon: {
        type: String,
        required: true,
    },

    /**
     * Ce que fait le bouton, en toutes lettres.
     *
     * Obligatoire : une icône seule n'a pas de nom accessible, et deux de ces
     * boutons n'en avaient effectivement aucun. La prop est requise pour que
     * l'oubli soit une erreur au montage plutôt qu'un silence au lecteur
     * d'écran.
     */
    label: {
        type: String,
        required: true,
    },

    /**
     * La couleur que prend l'icône au SURVOL. Au repos, elles sont toutes
     * grises.
     *
     * Deux écrans peignaient la suppression en rouge dès le repos. Dans une
     * liste, cela met un accent d'alerte sur chaque ligne et transforme
     * l'attention en bruit : l'utilisateur cesse de la voir, précisément là où
     * elle devrait compter.
     */
    ton: {
        type: String,
        default: 'neutre',
        validator: (valeur) => TONS.includes(valeur),
    },

    /**
     * Une vignette réduite, pour un bouton posé en surimpression sur autre
     * chose. La zone tactile, elle, ne rétrécit pas.
     */
    compact: {
        type: Boolean,
        default: false,
    },
})

const SURVOLS = {
    neutre: 'hover:text-text-main',
    danger: 'hover:text-accent-danger-deep',
    accent: 'hover:text-accent-primary-deep',
    info: 'hover:text-accent-info-deep',
}

const classes = computed(() => [
    'text-text-muted focus-visible:ring-accent-primary inline-flex items-center justify-center rounded-lg',
    'transition-colors focus-visible:ring-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-40',
    SURVOLS[props.ton],
    props.compact
        ? // La cible reste à 44 px par le pseudo-élément : 24 + 2 × 10.
          "relative size-6 before:absolute before:-inset-2.5 before:content-['']"
        : 'min-h-touch min-w-touch',
])
</script>

<template>
    <button type="button" :class="classes" :aria-label="label" v-bind="$attrs">
        <span class="material-symbols-outlined" :class="compact ? 'text-base' : 'text-lg'" aria-hidden="true">
            {{ icon }}
        </span>
    </button>
</template>

<script setup>
import {
    CADRE_DU_LOGO,
    DISPOSITIONS_DE_LA_MARQUE,
    TRACES_DE_LA_LETTRE,
    TRACES_DES_BARRES,
    TUILE_DU_LOGO,
} from '@/Utils/dessinDuLogo'

/**
 * Le logo de l'application et son nom.
 *
 * Le logo est tracé dans la page, depuis le dessin de public/logo.svg
 * (`Utils/dessinDuLogo.js`) : l'en-tête reste entier hors ligne, et ses
 * couleurs suivent les jetons `logo-*` de la charte. Il est posé sur la tuile
 * de l'icône de l'application, qui lui garde ses contrastes sur un fond teinté.
 * Il est décoratif : le nom écrit à côté nomme le lien qui entoure la marque,
 * et un nom posé aussi sur le dessin ferait annoncer « GymTracker GymTracker ».
 * Dans la barre, ce nom n'est visible qu'à partir de `xl`, faute de place, et
 * reste lu en deçà ; au-dessus d'une page, le logo se montre seul et le nom
 * reste lu (`DISPOSITIONS_DE_LA_MARQUE`).
 */
defineProps({
    disposition: {
        type: String,
        default: 'barre',
        validator: (valeur) => valeur in DISPOSITIONS_DE_LA_MARQUE,
    },
})
</script>

<template>
    <span class="inline-flex items-center" :class="DISPOSITIONS_DE_LA_MARQUE[disposition].sens">
        <svg
            xmlns="http://www.w3.org/2000/svg"
            :viewBox="CADRE_DU_LOGO"
            :class="[TUILE_DU_LOGO, DISPOSITIONS_DE_LA_MARQUE[disposition].logo]"
            aria-hidden="true"
            focusable="false"
            data-testid="logo-de-l-application"
        >
            <g class="fill-logo-lettre">
                <path v-for="trace in TRACES_DE_LA_LETTRE" :key="trace" :d="trace" />
            </g>
            <g class="fill-logo-barres">
                <path v-for="trace in TRACES_DES_BARRES" :key="trace" :d="trace" />
            </g>
        </svg>
        <span
            class="text-gradient font-display font-black tracking-tight uppercase italic"
            :class="DISPOSITIONS_DE_LA_MARQUE[disposition].nom"
        >
            GymTracker
        </span>
    </span>
</template>

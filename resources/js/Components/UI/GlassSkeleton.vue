<script setup>
import { computed, useAttrs } from 'vue'

/**
 * La place que prendra un contenu qui n'est pas encore arrivé.
 *
 * Le composant portait trois façons de dire son arrondi : une prop `variant`
 * (`text` / `circle` / `card`), une prop `borderRadius`, et la classe passée par
 * l'appelant. Deux des trois ne servaient à rien :
 *
 *  - `variant` donnait `rounded-2xl` à `text` COMME à `card` — les deux valeurs
 *    rendaient donc le même arrondi, et `circle` n'était employé nulle part ;
 *  - toutes ces classes tombaient ensuite sous un `border-radius` en style
 *    en ligne, qui l'emporte sur n'importe quelle classe. Onze appels
 *    demandaient `rounded-xl` et obtenaient les `0.5rem` de la valeur par
 *    défaut, sans que rien ne le signale.
 *
 * Il n'en reste qu'une : la classe. Le composant ne pose la sienne que si
 * l'appelant n'en a passé aucune, ce qui évite de faire dépendre le résultat de
 * l'ordre des utilitaires dans la feuille compilée.
 */
defineOptions({ inheritAttrs: false })

defineProps({
    width: {
        type: String,
        default: '100%',
    },

    height: {
        type: String,
        default: '1rem',
    },
})

const attrs = useAttrs()

/**
 * L'arrondi par défaut, sauf si l'appelant a déjà dit le sien.
 *
 * `attrs.class` peut être une chaîne, un tableau ou un objet selon la façon dont
 * l'appelant l'écrit ; `JSON.stringify` couvre les trois sans avoir à les
 * distinguer.
 */
const arrondiParDefaut = computed(() => (/\brounded-/.test(JSON.stringify(attrs.class ?? '')) ? '' : 'rounded-lg'))
</script>

<template>
    <div
        class="glass-skeleton relative overflow-hidden"
        :class="[arrondiParDefaut, attrs.class]"
        :style="{ width, height }"
        v-bind="{ ...attrs, class: undefined }"
    />
</template>

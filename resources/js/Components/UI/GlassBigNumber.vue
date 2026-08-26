<script setup>
import { computed, useAttrs, useId } from 'vue'

/**
 * Le grand champ chiffré des calculateurs : un nombre, son unité, rien d'autre.
 *
 * Trois outils — plaques, 1RM, échauffement — posaient la même question (« quel
 * poids ? ») avec six `<input>` écrits à la main. Les six portaient la même
 * intention et trois habillages différents : bordure simple ou double, fond
 * plein ou à 50 %, flou d'arrière-plan `sm`, `md` ou absent, et un survol qui
 * n'existait que sur l'un des trois.
 *
 * `GlassInput` ne convenait pas : il vise 44 px et du texte de 16 px, quand ces
 * champs-ci font 64 px et affichent le nombre en 30 px. Ce n'est pas le même
 * composant, c'est le même RÔLE — la valeur qu'on vient régler, et qui doit se
 * lire d'un coup d'œil à bout de bras, une barre chargée devant soi.
 *
 * Le suffixe est décoratif : il redit l'unité déjà présente dans le libellé, et
 * un lecteur d'écran qui l'annoncerait ferait doublon.
 */
defineOptions({ inheritAttrs: false })

defineProps({
    modelValue: {
        type: [String, Number],
        default: '',
    },

    /** Obligatoire : six champs de calcul, six libellés, aucun champ anonyme. */
    label: {
        type: String,
        required: true,
    },

    /** L'unité affichée à droite du nombre — `kg`, `réps`. */
    unite: {
        type: String,
        default: '',
    },
})

const emit = defineEmits(['update:modelValue'])

/**
 * Rendre un NOMBRE, pas la chaîne que le DOM contient.
 *
 * `v-model` sur un `<input type="number">` applique le modificateur `.number`
 * tout seul — le compilateur de Vue le sait à la lecture du `type`. Un composant
 * qui réémet `$event.target.value` perd ce cast en silence, et les trois
 * calculateurs se sont mis à comparer des chaînes : `'100' > '20'` est faux, et
 * la barre n'affichait plus aucun disque.
 *
 * Le champ vide reste une chaîne vide, et non zéro : `Number('')` vaut 0, et
 * 0 kg est une charge que quelqu'un peut vouloir saisir.
 *
 * Aucun repli n'est prévu pour une saisie illisible : un `<input type=number>`
 * ne rend jamais autre chose qu'une chaîne vide ou un nombre bien formé — taper
 * « - » y laisse `value` à vide.
 */
const enNombre = (brut) => (brut === '' ? '' : Number.parseFloat(brut))

const attrs = useAttrs()
const genere = useId()

const champId = computed(() => attrs.id ?? `grand-nombre-${genere}`)

/**
 * Les attributs de l'appelant, sauf ceux que le composant tient lui-même.
 *
 * `v-bind` est appliqué dans l'ordre d'écriture : laisser `id` et `class` dans
 * le lot les faisait écraser par le `undefined` du spread, et le champ perdait
 * l'identifiant que son propre label désigne.
 */
const reste = computed(() => {
    const { id: _id, class: _class, ...autres } = attrs

    return autres
})
</script>

<template>
    <div>
        <label :for="champId" class="font-display-label text-text-muted mb-2 block">{{ label }}</label>

        <div class="relative">
            <input
                v-bind="reste"
                :id="champId"
                type="number"
                :value="modelValue"
                class="font-display text-text-main placeholder-text-muted/50 focus:border-accent-primary focus:ring-accent-primary/20 border-border bg-surface-card/50 hover:bg-surface-card/80 focus:bg-surface-card/80 h-16 w-full rounded-2xl border px-4 text-center text-3xl font-black backdrop-blur-md transition-all outline-none focus:ring-2"
                :class="{ 'pr-14': unite }"
                @input="emit('update:modelValue', enNombre($event.target.value))"
            />

            <span
                v-if="unite"
                class="text-text-muted absolute top-1/2 right-4 -translate-y-1/2 font-bold"
                aria-hidden="true"
            >
                {{ unite }}
            </span>
        </div>
    </div>
</template>

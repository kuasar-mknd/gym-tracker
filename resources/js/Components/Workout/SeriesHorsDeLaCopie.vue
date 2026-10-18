<script setup>
/**
 * Ce qu'une copie hors ligne de la séance n'a pas encore (#2048, partie E).
 *
 * La copie de la séance ne prend son instantané que quand tout est
 * enregistré (`useCopieDeLaSeance`) : une série acceptée en ligne juste avant
 * la coupure, ou pendant qu'une autre écriture attendait, peut y manquer.
 * Chaque écriture directe de la séance que le serveur accepte se note
 * aussitôt, la séance et l'heure (`Utils/ecrituresAcceptees.js`). Servie
 * depuis une copie plus ancienne que cette note, la séance le dit : ces
 * séries ne sont pas perdues, et la personne ne les refait pas. La copie se
 * date de sa prop `workout`, l'instant où son instantané était vrai
 * (`dateDeLaProp`).
 */
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { dateDeLaProp } from '@/Utils/copiesServies'
import { heureCourte } from '@/Utils/date'
import { derniereEcritureAcceptee } from '@/Utils/ecrituresAcceptees'
import { useCopieHorsLigne } from '@/composables/useCopieHorsLigne'

const props = defineProps({
    /** L'identifiant de la séance montrée. */
    seance: { type: [Number, String], required: true },
})

const page = usePage()
const { marque } = useCopieHorsLigne()

/** L'instant de la copie, quand une écriture acceptée depuis y manque ; sinon `null`. */
const copieDepassee = computed(() => {
    if (marque.value === null) {
        return null
    }

    const copie = dateDeLaProp(marque.value, 'workout')
    const note = derniereEcritureAcceptee({ compte: page.props.auth?.user?.id, seance: props.seance })

    return copie !== null && note !== null && note > copie ? copie : null
})
</script>

<template>
    <p
        v-if="copieDepassee !== null"
        role="status"
        class="info-fill mb-4 flex items-start gap-2 rounded-xl p-3 text-sm font-medium"
        dusk="series-hors-de-la-copie"
    >
        <GlassIcon name="task_alt" size="sm" class="shrink-0" />
        <span>
            Des séries enregistrées après {{ heureCourte(new Date(copieDepassee)) }} n'apparaissent pas ici : elles sont
            bien enregistrées.
        </span>
    </p>
</template>

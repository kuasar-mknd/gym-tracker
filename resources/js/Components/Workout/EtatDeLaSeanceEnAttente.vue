<script setup>
/**
 * Ce que dit la page d'une séance que le serveur ne connaît pas encore tout
 * entière (#2049, `useSeanceEnAttente`).
 *
 * Tant que sa création attend la file, la séance le dit : elle sera
 * enregistrée au retour du réseau. Refusée par le serveur, ou partie de la
 * file sans que la page sache ce qu'elle est devenue, elle le dit aussi, et un
 * lien mène à l'accueil : la page est alors en lecture seule. Le texte porte
 * un identifiant, que les boutons désactivés d'une séance en attente visent
 * (`aria-describedby`).
 *
 * Terminée sans réponse du serveur, sa fin en file (`_finEnAttente`), la
 * séance le dit avec l'heure du geste, une séance du serveur comme une séance
 * démarrée sans réseau, et un lien mène à l'accueil : la personne reste sur
 * la séance close, l'accueil pouvant ne pas avoir de copie sur l'appareil.
 * Un refus l'emporte : la fin ne partira pas sans sa séance.
 */
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import {
    SEANCE_A_RETROUVER,
    SEANCE_EN_ATTENTE,
    SEANCE_NON_ENREGISTREE,
    SEANCE_REFUSEE,
    messageDeLaFinEnAttente,
} from '@/Utils/seanceEnAttente'
import {
    ETAT_A_RETROUVER,
    ETAT_EN_ATTENTE,
    ETAT_NON_ENREGISTREE,
    ETAT_REFUSEE,
    ID_DE_L_ETAT_DE_LA_SEANCE,
} from '@/composables/useSeanceEnAttente'

const props = defineProps({
    /** L'état de la séance (`useSeanceEnAttente`), ou null pour une séance du serveur. */
    etat: { type: String, default: null },
    /** La séance à l'écran : sa fin, quand elle attend la file. */
    seance: { type: Object, default: null },
})

const MESSAGES = {
    [ETAT_EN_ATTENTE]: SEANCE_EN_ATTENTE,
    [ETAT_REFUSEE]: SEANCE_REFUSEE,
    [ETAT_NON_ENREGISTREE]: SEANCE_NON_ENREGISTREE,
    [ETAT_A_RETROUVER]: SEANCE_A_RETROUVER,
}

const sansSeance = computed(() => props.etat === ETAT_REFUSEE || props.etat === ETAT_NON_ENREGISTREE)
const finEnAttente = computed(() => !sansSeance.value && props.seance?._finEnAttente !== undefined)

const message = computed(() =>
    finEnAttente.value ? messageDeLaFinEnAttente(props.seance.ended_at) : (MESSAGES[props.etat] ?? null),
)
</script>

<template>
    <div
        v-if="message !== null"
        role="status"
        :class="['mb-4 flex items-start gap-3 rounded-2xl p-4 shadow-lg', sansSeance ? 'danger-fill' : 'info-fill']"
        dusk="etat-de-la-seance-en-attente"
    >
        <GlassIcon name="cloud_off" class="shrink-0" />
        <div class="min-w-0 flex-1 space-y-2">
            <p :id="ID_DE_L_ETAT_DE_LA_SEANCE" class="text-sm font-bold">{{ message }}</p>
            <Link
                v-if="finEnAttente || etat !== ETAT_EN_ATTENTE"
                :href="route('dashboard')"
                class="focus-visible:ring-accent-primary min-h-touch inline-flex items-center text-sm font-bold underline focus-visible:ring-2 focus-visible:outline-none"
                dusk="seance-en-attente-accueil"
            >
                Accueil
            </Link>
        </div>
    </div>
</template>

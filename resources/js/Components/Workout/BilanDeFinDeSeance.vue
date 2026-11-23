<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import Modal from '@/Components/UI/Modal.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { seriesValidees, volumeDesSeriesValidees } from '@/Utils/seriesValidees'
import { entier, poids } from '@/Utils/nombre'

/**
 * Le bilan de fin de séance (#2434) : ce que la séance a donné, une fois close.
 *
 * Il s'ouvre sur la page de la séance après la fin, jamais avant : la question
 * « Terminer la séance ? » reste simple. Sa durée, son volume et ses séries
 * validées viennent de la copie locale, la même avec ou sans réseau.
 *
 * Fin enregistrée par le serveur : ses records de poids (que lui seul connaît),
 * et « Reprendre » tant que la réouverture est permise (`peutReprendre`,
 * `WorkoutPolicy::reopen()`, quinze minutes). « Fermer » mène à l'accueil.
 *
 * Fin en file, sans réseau : « Enregistrée sur cet appareil », sans records ni
 * « Reprendre » (la réouverture est en ligne seulement), et « Fermer » laisse
 * la personne sur la séance.
 */
const props = defineProps({
    /** Null tant que le bilan n'est pas ouvert. */
    bilan: { type: Object, default: null },
    /** La séance close : son début et sa fin donnent la durée, ses séries le reste. */
    seance: { type: Object, default: null },
    /** La réouverture est partie, et la réponse du serveur n'est pas revenue. */
    enCours: { type: Boolean, default: false },
})

const emit = defineEmits(['fermer', 'reprendre'])

const resume = computed(() => {
    const series = (props.seance?.workout_lines ?? []).flatMap((ligne) => ligne.sets ?? [])
    const minutes = Math.max(
        0,
        Math.round((new Date(props.seance?.ended_at) - new Date(props.seance?.started_at)) / 60000),
    )

    return {
        duree: isNaN(minutes)
            ? '—'
            : minutes >= 60
              ? `${entier(Math.floor(minutes / 60))} h ${String(minutes % 60).padStart(2, '0')}`
              : `${entier(minutes)} min`,
        volume: `${entier(volumeDesSeriesValidees(series))} kg`,
        series: entier(seriesValidees(series).length),
    }
})

/** « Fermer » reprend le focus quand « Reprendre » disparaît sous le doigt : il le perdrait sinon. */
const fermer = ref(null)

watch(
    () => props.bilan?.reprisePerimee === true,
    async (perimee) => {
        if (perimee) {
            await nextTick()
            fermer.value?.$el?.focus()
        }
    },
)

const peutReprendre = computed(() => props.bilan?.enAttente !== true && props.bilan?.peutReprendre === true)
const records = computed(() => (props.bilan?.enAttente === true ? [] : (props.bilan?.records ?? [])))
</script>

<template>
    <Modal
        :show="bilan !== null"
        position="feuille"
        max-width="sm"
        aria-labelledby="bilan-de-fin-titre"
        @close="emit('fermer')"
    >
        <div class="pb-feuille-6 px-6 pt-1" dusk="bilan-de-fin">
            <h3 id="bilan-de-fin-titre" class="titre-plat mb-4" dusk="bilan-de-fin-titre">Séance terminée</h3>
            <dl class="mb-4 grid grid-cols-3 gap-2" dusk="finish-workout-bilan">
                <div>
                    <dt class="text-text-muted text-xs">Durée</dt>
                    <dd
                        class="text-text-main text-xl font-medium whitespace-nowrap tabular-nums"
                        dusk="finish-workout-duree"
                    >
                        {{ resume.duree }}
                    </dd>
                </div>
                <div>
                    <dt class="text-text-muted text-xs">Volume</dt>
                    <dd
                        class="text-text-main text-xl font-medium whitespace-nowrap tabular-nums"
                        dusk="finish-workout-volume"
                    >
                        {{ resume.volume }}
                    </dd>
                </div>
                <div>
                    <dt class="text-text-muted text-xs">Séries</dt>
                    <dd
                        class="text-text-main text-xl font-medium whitespace-nowrap tabular-nums"
                        dusk="finish-workout-series"
                    >
                        {{ resume.series }}
                    </dd>
                </div>
            </dl>
            <div
                v-if="bilan?.enAttente"
                class="bg-surface-sunken/60 mb-4 flex items-center gap-3 rounded-2xl px-4 py-3"
                role="status"
            >
                <GlassIcon name="check_circle" class="text-accent-primary shrink-0" />
                <span class="text-text-main text-sm font-medium">
                    <span class="block" dusk="bilan-de-fin-sur-l-appareil">Enregistrée sur cet appareil</span>
                    <span class="text-text-muted block text-xs font-normal">
                        Elle part au retour du réseau. Les records arrivent avec la réponse du serveur.
                    </span>
                </span>
            </div>
            <div v-if="records.length > 0" class="mb-4" dusk="bilan-de-fin-records">
                <p class="text-text-muted mb-1 px-1 text-sm">Records</p>
                <ul class="bg-surface-sunken/60 rounded-2xl p-1">
                    <li
                        v-for="(record, index) in records"
                        :key="`${index}-${record.exercice}`"
                        class="flex items-baseline justify-between gap-3 px-3 py-2"
                    >
                        <span class="text-text-main min-w-0 text-sm break-words">{{ record.exercice }}</span>
                        <span class="text-text-main shrink-0 font-medium tabular-nums">{{ poids(record.valeur) }}</span>
                    </li>
                </ul>
            </div>
            <p
                role="status"
                :class="{ 'text-text-muted mb-4 text-sm': bilan?.reprisePerimee }"
                dusk="bilan-de-fin-reprise-perimee"
            >
                {{ bilan?.reprisePerimee ? 'Le délai pour reprendre est passé.' : '' }}
            </p>
            <div class="mt-2 flex flex-col gap-3">
                <GlassButton
                    ref="fermer"
                    variant="accent"
                    class="bouton-de-bilan w-full"
                    dusk="bilan-de-fin-fermer"
                    :disabled="enCours"
                    @click="emit('fermer')"
                >
                    Fermer
                </GlassButton>
                <GlassButton
                    v-if="peutReprendre"
                    variant="secondary"
                    class="bouton-de-bilan w-full"
                    dusk="bilan-de-fin-reprendre"
                    :loading="enCours"
                    @click="emit('reprendre')"
                >
                    Reprendre
                </GlassButton>
            </div>
        </div>
    </Modal>
</template>

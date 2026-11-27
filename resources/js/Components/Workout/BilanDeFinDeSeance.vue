<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import Modal from '@/Components/UI/Modal.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import ChiffresDeLaSeance from '@/Components/Workout/ChiffresDeLaSeance.vue'
import ListeDesRecords from '@/Components/Workout/ListeDesRecords.vue'

/**
 * Le bilan de fin de séance (#2434) : ce que la séance a donné, une fois close.
 *
 * Il s'ouvre sur la page de la séance après la fin. La feuille d'avant, qui
 * demande d'enregistrer, porte déjà les mêmes trois chiffres
 * (`ChiffresDeLaSeance`), venus de la copie locale, avec ou sans réseau.
 *
 * Fin enregistrée par le serveur : ses records de poids (ceux que la file a écrits),
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
            <ChiffresDeLaSeance :seance="seance" />
            <div
                v-if="bilan?.enAttente"
                class="bg-surface-sunken/60 mb-4 flex items-center gap-3 rounded-2xl px-4 py-3"
                role="status"
            >
                <GlassIcon name="check_circle" class="text-accent-primary-deep shrink-0" />
                <span class="text-text-main text-sm font-medium">
                    <span class="block" dusk="bilan-de-fin-sur-l-appareil">Enregistrée sur cet appareil</span>
                    <span class="text-text-muted block text-xs font-normal">
                        Elle part au retour du réseau. Les records arrivent avec la réponse du serveur.
                    </span>
                </span>
            </div>
            <ListeDesRecords :records="records" />
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

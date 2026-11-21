<script setup>
import { computed, ref, watch } from 'vue'
import Modal from '@/Components/UI/Modal.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassSegmented from '@/Components/UI/GlassSegmented.vue'
import { formatToLocalISO } from '@/Utils/date'
import { estUneSeanceOubliee, finProposeeDUneSeance, jourEtHeure } from '@/Utils/seanceOubliee'

/**
 * La question « Terminer la séance ? » (#1961, #2049).
 *
 * Sans réseau, la fin entre en file à l'heure du geste, et la séance se montre
 * close sur sa page : la modale n'a rien à dire d'une fin non confirmée. Elle
 * ne dit que ce qui empêche de terminer : une modification encore en route
 * vers le serveur, qui arriverait sinon après la fin et serait refusée.
 *
 * Une séance oubliée, ouverte depuis plus de douze heures (#2319), propose
 * « Terminer à l'heure de la dernière série saisie » (à défaut, celle de son
 * démarrage), ou une heure choisie, jamais avant le début ni dans le futur. La confirmation
 * porte alors l'heure retenue (un instant ISO) ; sans proposition, elle ne
 * porte rien, et la fin est celle du geste.
 */
const props = defineProps({
    show: { type: Boolean, required: true },
    /** Les modifications encore en route vers le serveur à la dernière tentative : la séance ne se ferme pas sans elles (#1961). */
    enAttente: { type: Number, default: 0 },
    /**
     * La clôture attend que les modifications partent : « Confirmer » le montre, et n'est pas rappuyable.
     * « Annuler », le fond ou Échap l'abandonnent (`close`) : la séance reste ouverte.
     */
    enCours: { type: Boolean, default: false },
    /** La séance qu'on termine : son début et ses séries disent si elle est oubliée, et à quelle heure la clore. */
    seance: { type: Object, default: null },
})

const emit = defineEmits(['close', 'confirm'])

/** Le début de la séance (instant ISO) : la fin choisie ne le précède pas. */
const debut = ref(null)

/**
 * Pour une séance oubliée : l'heure de sa dernière série saisie (instant ISO),
 * ou son début. Null sinon. Lue à l'ouverture de la question, pas à chaque rendu.
 */
const finProposee = ref(null)

/** La proposition vient d'une série saisie ; faux, c'est l'heure de démarrage de la séance. */
const depuisUneSerie = ref(true)

const oubliee = computed(() => finProposee.value !== null)

const choixPossibles = computed(() => [
    { value: 'derniere', label: depuisUneSerie.value ? 'Dernière série saisie' : 'Heure de démarrage' },
    { value: 'autre', label: 'Autre heure' },
])

const choix = ref('derniere')
const heureChoisie = ref('')

/** Maintenant, à l'ouverture : la borne haute du champ. */
const maintenant = ref(new Date())

watch(
    () => props.show,
    (ouverte) => {
        if (!ouverte) {
            return
        }

        choix.value = 'derniere'
        maintenant.value = new Date()
        debut.value = props.seance?.started_at ?? null
        const proposition = estUneSeanceOubliee(debut.value, maintenant.value)
            ? finProposeeDUneSeance(props.seance, maintenant.value)
            : null

        finProposee.value = proposition?.instant ?? null
        depuisUneSerie.value = proposition?.serie ?? true
        heureChoisie.value = finProposee.value === null ? '' : formatToLocalISO(finProposee.value)
    },
    { immediate: true },
)

const minimum = computed(() => formatToLocalISO(debut.value))
const maximum = computed(() => formatToLocalISO(maintenant.value))

/** L'heure choisie à la main, quand elle ne tient pas entre le début et maintenant. */
const erreurDeLHeure = computed(() => {
    if (!oubliee.value || choix.value !== 'autre') {
        return ''
    }

    const heure = new Date(heureChoisie.value)

    if (heureChoisie.value === '' || isNaN(heure.getTime())) {
        return 'Choisis une date et une heure.'
    }

    if (heure < new Date(minimum.value)) {
        return 'La fin ne peut pas précéder le début de la séance.'
    }

    // Comparée à l'instant où l'on confirme, pas à celui de l'ouverture.
    return heure > new Date() ? 'La fin ne peut pas être dans le futur.' : ''
})

const confirmer = () => {
    if (!oubliee.value) {
        emit('confirm')

        return
    }

    if (erreurDeLHeure.value !== '') {
        return
    }

    /*
     * Le champ ne garde que la minute : choisir la minute du début, qui a des
     * secondes, donnerait une fin d'avant lui, que le serveur refuse. La fin
     * ne précède jamais le début.
     */
    const heure = new Date(choix.value === 'derniere' ? finProposee.value : heureChoisie.value)
    const premiere = new Date(debut.value)

    emit('confirm', (heure < premiere ? premiere : heure).toISOString())
}

const avisDAttente = computed(() =>
    props.enAttente > 1
        ? `${props.enAttente} modifications sont encore en route vers le serveur. La séance reste ouverte : réessaie dans un instant.`
        : 'Une modification est encore en route vers le serveur. La séance reste ouverte : réessaie dans un instant.',
)
</script>

<template>
    <Modal :show="show" @close="emit('close')" max-width="sm" aria-labelledby="finish-workout-modal-title">
        <div class="p-6 text-center">
            <h3 id="finish-workout-modal-title" class="titre-plat mb-6" dusk="finish-workout-modal-title">
                Terminer la séance ?
            </h3>
            <p
                v-if="enCours"
                role="status"
                class="text-text-muted mb-6 text-sm font-medium"
                dusk="finish-workout-sending"
            >
                Envoi des modifications en attente…
            </p>
            <p
                v-else-if="enAttente > 0"
                role="status"
                class="bg-accent-danger/10 text-accent-danger-deep mb-6 rounded-xl p-3 text-sm font-bold"
                dusk="finish-workout-pending"
            >
                {{ avisDAttente }}
            </p>
            <div v-if="oubliee" class="mb-6 space-y-4 text-left" dusk="finish-workout-forgotten">
                <p class="text-text-muted text-sm font-medium">
                    Cette séance est ouverte depuis le {{ jourEtHeure(debut) }}.
                </p>
                <GlassSegmented v-model="choix" :options="choixPossibles" label="Heure de fin" bloc />
                <p
                    v-if="choix === 'derniere'"
                    class="text-text-main text-sm font-medium"
                    dusk="finish-workout-proposed"
                >
                    Terminer à l'heure {{ depuisUneSerie ? 'de la dernière série saisie' : 'de démarrage' }} :
                    {{ jourEtHeure(finProposee) }}.
                </p>
                <GlassInput
                    v-else
                    v-model="heureChoisie"
                    type="datetime-local"
                    label="Heure de fin"
                    :min="minimum"
                    :max="maximum"
                    :error="erreurDeLHeure"
                    dusk="finish-workout-end-input"
                />
            </div>
            <div class="flex flex-col-reverse gap-3 min-[24rem]:flex-row">
                <GlassButton variant="secondary" @click="emit('close')" class="bouton-de-bilan min-w-0 flex-1">
                    Annuler
                </GlassButton>
                <GlassButton
                    variant="accent"
                    id="confirm-finish-button"
                    dusk="confirm-finish-button"
                    :loading="enCours"
                    :disabled="erreurDeLHeure !== ''"
                    @click="confirmer"
                    class="bouton-de-bilan min-w-0 flex-1"
                >
                    Confirmer
                </GlassButton>
            </div>
        </div>
    </Modal>
</template>

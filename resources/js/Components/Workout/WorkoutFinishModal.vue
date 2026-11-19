<script setup>
import { computed, ref, watch } from 'vue'
import Modal from '@/Components/UI/Modal.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassSegmented from '@/Components/UI/GlassSegmented.vue'
import { formatToLocalISO } from '@/Utils/date'
import { estUneSeanceOubliee, finProposeeDUneSeance, jourEtHeure } from '@/Utils/seanceOubliee'
import { seriesValidees, volumeDesSeriesValidees } from '@/Utils/seriesValidees'
import { entier } from '@/Utils/nombre'

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
 *
 * C'est aussi le bilan de la séance (#2434) : sa durée, son volume et ses séries
 * validées, tirés de la copie locale, donc les mêmes avec ou sans réseau. Rien
 * n'est enregistré avant « Enregistrer » ; « Reprendre » (`close`) laisse la
 * séance ouverte, et rien ne la rouvre une fois enregistrée, d'où la note. Les
 * records, que seul le serveur connaît et seulement après l'enregistrement, n'y
 * figurent pas : l'accueil, où mène la fin, les dit.
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

/** L'instant où la séance finirait : l'heure retenue d'une séance oubliée, sinon maintenant. */
const finDuBilan = computed(() => {
    if (!oubliee.value) {
        return maintenant.value
    }

    const retenue = new Date(choix.value === 'derniere' ? finProposee.value : heureChoisie.value)

    return isNaN(retenue.getTime()) ? new Date(finProposee.value) : retenue
})

/** Ce que la séance a donné : durée, volume et séries validées, lus de la copie locale. */
const bilan = computed(() => {
    const series = (props.seance?.workout_lines ?? []).flatMap((ligne) => ligne.sets ?? [])
    const debutDeLaSeance = new Date(props.seance?.started_at ?? NaN)
    const minutes = Math.max(0, Math.round((finDuBilan.value - debutDeLaSeance) / 60000))

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

const avisDAttente = computed(() =>
    props.enAttente > 1
        ? `${props.enAttente} modifications sont encore en route vers le serveur. La séance reste ouverte : réessaie dans un instant.`
        : 'Une modification est encore en route vers le serveur. La séance reste ouverte : réessaie dans un instant.',
)
</script>

<template>
    <Modal :show="show" @close="emit('close')" max-width="sm" aria-labelledby="finish-workout-modal-title">
        <div class="p-6 text-center">
            <h3 id="finish-workout-modal-title" class="titre-carte mb-4" dusk="finish-workout-modal-title">
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
            <dl class="bg-surface-sunken/60 mb-4 rounded-2xl p-1 text-left" dusk="finish-workout-bilan">
                <div class="flex items-baseline justify-between gap-3 px-3 py-2">
                    <dt class="text-text-muted text-sm">Durée</dt>
                    <dd class="text-text-main font-medium tabular-nums" dusk="finish-workout-duree">
                        {{ bilan.duree }}
                    </dd>
                </div>
                <div class="flex items-baseline justify-between gap-3 px-3 py-2">
                    <dt class="text-text-muted text-sm">Volume</dt>
                    <dd class="text-text-main font-medium tabular-nums" dusk="finish-workout-volume">
                        {{ bilan.volume }}
                    </dd>
                </div>
                <div class="flex items-baseline justify-between gap-3 px-3 py-2">
                    <dt class="text-text-muted text-sm">Séries</dt>
                    <dd class="text-text-main font-medium tabular-nums" dusk="finish-workout-series">
                        {{ bilan.series }}
                    </dd>
                </div>
            </dl>
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
            <p class="text-text-muted mb-6 text-sm">Une séance enregistrée ne se rouvre plus.</p>
            <div class="flex flex-col-reverse gap-3">
                <GlassButton variant="secondary" @click="emit('close')" class="w-full"> Reprendre </GlassButton>
                <GlassButton
                    variant="primary"
                    id="confirm-finish-button"
                    dusk="confirm-finish-button"
                    :loading="enCours"
                    :disabled="erreurDeLHeure !== ''"
                    @click="confirmer"
                    class="w-full"
                >
                    Enregistrer
                </GlassButton>
            </div>
        </div>
    </Modal>
</template>

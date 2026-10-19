<script setup>
/**
 * Les actions sous les exercices d'une séance en cours : ajouter un exercice,
 * démarrer un repos, enregistrer la séance comme modèle, la terminer.
 *
 * « Modèle » est désactivé quand le serveur ne répond pas (#2048) : sans
 * réseau, l'enregistrement échouait sans un mot. La raison s'affiche sous les
 * boutons, et le bouton la porte pour un lecteur d'écran. Sur une copie hors
 * ligne, « Terminer » et « Modèle » ne partiraient pas, même le serveur revenu
 * (la copie attend que la file se vide pour se recharger) : ils se
 * désactivent aussi, avec la raison du bandeau de la copie. Sur une séance
 * démarrée sans réseau (#2049), que le serveur ne connaît pas encore, ils se
 * désactivent de même, avec ce que dit l'état de la séance
 * (`EtatDeLaSeanceEnAttente.vue`). Ajouter un exercice et le repos restent :
 * ils passent par la file, ou ne quittent pas l'appareil.
 */
import { computed } from 'vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import { useEtatDuReseau } from '@/composables/useEtatDuReseau'
import { useCopieHorsLigne } from '@/composables/useCopieHorsLigne'
import { ID_DE_L_ETAT_DE_LA_SEANCE } from '@/composables/useSeanceEnAttente'

const props = defineProps({
    /** L'enregistrement comme modèle attend la réponse du serveur. */
    enregistrementDuModele: { type: Boolean, default: false },
    /** La séance a été démarrée sans réseau, et sa création attend la file. */
    seanceEnAttente: { type: Boolean, default: false },
})

const emit = defineEmits(['ajouter-un-exercice', 'demarrer-un-repos', 'enregistrer-comme-modele', 'terminer'])

const { joignable } = useEtatDuReseau()
const { surUneCopie, raisonDUneEcriture } = useCopieHorsLigne()

/** Pourquoi « Modèle » est désactivé, pour un lecteur d'écran. */
const raisonDuModele = computed(() => {
    if (props.seanceEnAttente) {
        return ID_DE_L_ETAT_DE_LA_SEANCE
    }

    return joignable.value ? raisonDUneEcriture.value : 'modele-sans-reseau'
})
</script>

<template>
    <div class="mt-8 space-y-3 px-1">
        <!--
            Pas de variante : la carte pleine. Ajouter un exercice est
            l'action courante de cette page ; « Modele », en dessous, est
            occasionnelle et prend le contour. Elles etaient inversees —
            et invisiblement, puisque ni `secondary` ni `solid`
            n'existaient dans l'objet de classes.
        -->
        <GlassButton @click="emit('ajouter-un-exercice')" class="w-full" dusk="add-exercise-existing"
            >Ajouter un exercice</GlassButton
        >
        <GlassButton variant="secondary" @click="emit('demarrer-un-repos')" class="w-full" dusk="open-rest-timer"
            >Démarrer un repos</GlassButton
        >
        <div class="grid grid-cols-2 gap-3">
            <GlassButton
                variant="secondary"
                @click="emit('enregistrer-comme-modele')"
                :loading="enregistrementDuModele"
                :disabled="!joignable || surUneCopie || seanceEnAttente"
                :aria-describedby="raisonDuModele"
                class="w-full"
                dusk="enregistrer-comme-modele"
                >Modèle</GlassButton
            >
            <GlassButton
                variant="primary"
                @click="emit('terminer')"
                :disabled="surUneCopie || seanceEnAttente"
                :aria-describedby="seanceEnAttente ? ID_DE_L_ETAT_DE_LA_SEANCE : raisonDUneEcriture"
                class="w-full"
                id="finish-workout-mobile"
                dusk="finish-workout-mobile"
                >Terminer</GlassButton
            >
        </div>
        <p v-if="!joignable" id="modele-sans-reseau" class="text-text-muted text-center text-sm font-medium">
            Il faut le réseau pour enregistrer la séance comme modèle.
        </p>
    </div>
</template>

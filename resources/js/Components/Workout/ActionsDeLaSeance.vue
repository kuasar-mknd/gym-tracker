<script setup>
/**
 * Les actions sous les exercices d'une séance en cours : ajouter un exercice,
 * démarrer un repos, enregistrer la séance comme modèle, la terminer.
 *
 * « Modèle » est désactivé quand le serveur ne répond pas (#2048) : sans
 * réseau, l'enregistrement échouait sans un mot. La raison s'affiche sous les
 * boutons, et le bouton la porte pour un lecteur d'écran.
 */
import GlassButton from '@/Components/UI/GlassButton.vue'
import { useEtatDuReseau } from '@/composables/useEtatDuReseau'

defineProps({
    /** L'enregistrement comme modèle attend la réponse du serveur. */
    enregistrementDuModele: { type: Boolean, default: false },
})

const emit = defineEmits(['ajouter-un-exercice', 'demarrer-un-repos', 'enregistrer-comme-modele', 'terminer'])

const { joignable } = useEtatDuReseau()
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
                :disabled="!joignable"
                :aria-describedby="joignable ? undefined : 'modele-sans-reseau'"
                class="w-full"
                dusk="enregistrer-comme-modele"
                >Modèle</GlassButton
            >
            <GlassButton
                variant="primary"
                @click="emit('terminer')"
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

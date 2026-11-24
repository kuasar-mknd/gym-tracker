<script setup>
/**
 * Les actions sous les exercices d'une séance en cours : ajouter un exercice,
 * démarrer un repos, enregistrer la séance comme modèle, la terminer.
 *
 * « Modèle » est désactivé quand le serveur ne répond pas (#2048) : sans
 * réseau, l'enregistrement échouait sans un mot. La raison s'affiche sous les
 * boutons, et le bouton la porte pour un lecteur d'écran. Sur une copie hors
 * ligne, « Modèle » ne partirait pas, même le serveur revenu (la copie attend
 * que la file se vide pour se recharger) : il se désactive aussi, avec la
 * raison du bandeau de la copie. Sur une séance démarrée sans réseau (#2049),
 * que le serveur ne connaît pas encore, il se désactive de même, avec ce que
 * dit l'état de la séance (`EtatDeLaSeanceEnAttente.vue`).
 *
 * « Abandonner » ne se montre que sur une séance vide, à la place de « Terminer » :
 * la question, puis la suppression de la séance (`useAbandonDeLaSeance`). Le serveur seul la
 * supprime : sans réseau, sur une copie hors ligne et sur une séance démarrée
 * sans réseau, « Terminer » reste à sa place.
 *
 * « Terminer » reste ouvert (#2049) : la fin passe par la file hors
 * ligne, à l'heure du geste, et n'envoie rien que la page tire d'une copie
 * (la séance, l'heure). Ajouter un exercice et le repos restent aussi : ils
 * passent par la file, ou ne quittent pas l'appareil.
 */
import { computed } from 'vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import { useAbandonDeLaSeance } from '@/composables/useAbandonDeLaSeance'
import { useEtatDuReseau } from '@/composables/useEtatDuReseau'
import { useCopieHorsLigne } from '@/composables/useCopieHorsLigne'
import { ID_DE_L_ETAT_DE_LA_SEANCE } from '@/composables/useSeanceEnAttente'

const props = defineProps({
    /** L'enregistrement comme modèle attend la réponse du serveur. */
    enregistrementDuModele: { type: Boolean, default: false },
    /** La séance a été démarrée sans réseau, et sa création attend la file. */
    seanceEnAttente: { type: Boolean, default: false },
    /**
     * La séance n'a pas encore d'exercice : ni repos ni modèle à proposer, et rien
     * à terminer : « Abandonner » prend la place de « Terminer », qui reviendra
     * avec le premier exercice.
     */
    vide: { type: Boolean, default: false },
    /** La séance que « Abandonner » supprime. */
    idDeLaSeance: { type: [Number, String], default: null },
})

const emit = defineEmits(['ajouter-un-exercice', 'demarrer-un-repos', 'enregistrer-comme-modele', 'terminer'])

const { joignable } = useEtatDuReseau()
const { surUneCopie, raisonDUneEcriture } = useCopieHorsLigne()
const abandon = useAbandonDeLaSeance(() => props.vide)

/**
 * Sans réseau, sur une copie hors ligne ou sur une séance que le serveur ne
 * connaît pas encore, la suppression ne partirait pas : « Terminer » reste
 * alors la sortie d'une séance vide (#2049), sans bouton désactivé.
 */
const peutAbandonner = computed(
    () => props.vide && props.idDeLaSeance !== null && joignable.value && !surUneCopie.value && !props.seanceEnAttente,
)

/** Pourquoi « Modèle » est désactivé, pour un lecteur d'écran. */
const raisonDuModele = computed(() => {
    if (props.seanceEnAttente) {
        return ID_DE_L_ETAT_DE_LA_SEANCE
    }

    return joignable.value ? raisonDUneEcriture.value : 'modele-sans-reseau'
})
</script>

<template>
    <div
        class="bureau:flex bureau:flex-col bureau:gap-3 bureau:px-0 bureau:mt-0 bureau:sticky bureau:top-sure-48 bureau:col-start-2 bureau:self-start mt-8 space-y-3 px-1"
    >
        <!--
            Les trois gestes de la séance : sur téléphone, la barre d'outils
            flottante au-dessus de la barre du bas (#2434) ; sur bureau, la
            colonne d'avant (`contents` : la barre n'a plus de boîte, ses
            boutons rejoignent la colonne). Le même bouton, le même `dusk`, sur
            l'un et l'autre : la barre ne double rien. Pas de variante pour
            « Exercice » : la carte pleine, l'action courante de cette page ;
            « Repos » prend le contour, « Terminer » est l'action primaire.
        -->
        <div
            class="verre-epais rounded-capsule bureau:contents au-dessus-du-menu left-sure-4 right-sure-4 z-flottant @container fixed mb-0 flex gap-2 p-2"
            role="group"
            aria-label="Actions de la séance"
            dusk="barre-d-outils-de-la-seance"
        >
            <GlassButton
                @click="emit('ajouter-un-exercice')"
                :disabled="abandon.enCours.value"
                class="bouton-de-barre bureau:w-full flex-auto"
                dusk="add-exercise-existing"
            >
                <GlassIcon name="add" size="sm" class="bureau:hidden @max-[19rem]:hidden" />
                <span class="bureau:hidden">Exercice</span>
                <span class="bureau:inline hidden">Ajouter un exercice</span>
            </GlassButton>
            <slot v-if="$slots.repos" name="repos" />
            <GlassButton
                v-else-if="!vide"
                variant="secondary"
                @click="emit('demarrer-un-repos')"
                class="bouton-de-barre bureau:w-full flex-auto"
                dusk="open-rest-timer"
            >
                <GlassIcon name="timer" size="sm" class="bureau:hidden @max-[19rem]:hidden" />
                <span class="bureau:hidden">Repos</span>
                <span class="bureau:inline hidden">Démarrer un repos</span>
            </GlassButton>
            <GlassButton
                v-if="peutAbandonner"
                variant="danger"
                @click="abandon.demander(idDeLaSeance)"
                :loading="abandon.enCours.value"
                class="bouton-de-barre bureau:w-full flex-auto"
                dusk="abandonner-la-seance"
                >Abandonner</GlassButton
            >
            <GlassButton
                v-else
                variant="accent"
                @click="emit('terminer')"
                :disabled="abandon.enCours.value"
                class="bouton-de-barre bureau:w-full flex-auto"
                id="finish-workout-mobile"
                dusk="finish-workout-mobile"
                >Terminer</GlassButton
            >
        </div>
        <!--
            « Modèle » enregistre une séance et quitte la page : sur téléphone, il
            n'occupe plus la largeur, que l'on touche pour fermer le clavier. Petit,
            effacé, à droite et à distance de la liste, un toucher dans le vide ne
            l'atteint plus (#2434). Sur bureau, pas de clavier à fermer : il reprend
            la largeur de la colonne, mais garde la variante effacée.
        -->
        <div v-if="!vide" class="bureau:block bureau:pt-0 flex justify-end pt-6">
            <GlassButton
                variant="ghost"
                @click="emit('enregistrer-comme-modele')"
                :loading="enregistrementDuModele"
                :disabled="!joignable || surUneCopie || seanceEnAttente"
                :aria-describedby="raisonDuModele"
                class="bureau:w-full"
                dusk="enregistrer-comme-modele"
                >Modèle</GlassButton
            >
        </div>
        <p v-if="!joignable && !vide" id="modele-sans-reseau" class="text-text-muted text-center text-sm font-medium">
            Il faut le réseau pour enregistrer la séance comme modèle.
        </p>
        <ConfirmDialog
            :ouvert="abandon.ouvert.value"
            titre="Abandonner cette séance ?"
            description="Elle est vide : elle sera supprimée."
            libelle-confirmation="Abandonner"
            @confirmer="abandon.confirmer"
            @annuler="abandon.annuler"
        />
    </div>
</template>

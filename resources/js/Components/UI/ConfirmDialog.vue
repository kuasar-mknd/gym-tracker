<script setup>
import Modal from '@/Components/UI/Modal.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import { computed, useId } from 'vue'

/**
 * Demander confirmation avant un geste irréversible.
 *
 * L'application posait la question de deux façons. Deux écrans ouvraient une
 * modale — titre, phrase nommant l'objet visé, « Annuler » discret et
 * « Supprimer » rouge. Les quatorze autres appelaient `confirm()`, la boîte
 * native du navigateur.
 *
 * Ce n'est pas qu'une question d'apparence. Une boîte native :
 *
 *  - se présente comme une alerte SYSTÈME préfixée du nom de domaine, ce qui la
 *    fait ressembler à une fenêtre malveillante autant qu'à une question de
 *    l'application ;
 *  - bloque le fil d'exécution du navigateur, animations comprises ;
 *  - ne peut porter ni mise en forme, ni le nom de ce qu'on s'apprête à
 *    perdre — juste une phrase ;
 *  - n'est pas traduisible : ses boutons « OK » et « Cancel » suivent la langue
 *    du navigateur, pas celle de l'interface. Un utilisateur français pouvait
 *    donc lire une question en français sous deux boutons en anglais ;
 *  - se comporte différemment sur mobile, où plusieurs navigateurs la
 *    suppriment purement et simplement après quelques appels — le geste
 *    s'exécute alors SANS question.
 *
 * Ce dernier point est le motif décisif : une confirmation qui peut disparaître
 * n'est pas une confirmation.
 *
 * Le composant fixe aussi ce que quatorze appels séparés laissaient dériver : le
 * tutoiement, la ponctuation, et le fait qu'un message reste en anglais sans que
 * personne ne s'en aperçoive — il y en avait un.
 */
const props = defineProps({
    /** Ce qui est en jeu, sous forme de question courte. */
    titre: {
        type: String,
        required: true,
    },

    /**
     * Ce qui sera perdu, nommément.
     *
     * C'est la vraie valeur ajoutée sur `confirm()` : « Supprimer cette
     * entrée ? » ne dit pas LAQUELLE, et l'utilisateur qui hésite doit fermer
     * pour aller vérifier.
     */
    description: {
        type: String,
        default: '',
    },

    /** Ouvert ou non. Le parent garde l'objet visé de son côté. */
    ouvert: {
        type: Boolean,
        default: false,
    },

    /** Le libellé du bouton qui agit. « Supprimer » n'est pas toujours le mot. */
    libelleConfirmation: {
        type: String,
        default: 'Supprimer',
    },

    /** Vrai tant que la requête est en vol : le bouton se verrouille. */
    enCours: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['confirmer', 'annuler'])

/*
 * Un identifiant unique par instance, pour `aria-labelledby`.
 *
 * Une constante partagée donnerait le même `id` à deux dialogues montés en même
 * temps, et un lecteur d'écran annoncerait le titre du mauvais. `useId()` est là
 * pour ça depuis Vue 3.5.
 */
const idTitre = useId()

const decrit = computed(() => props.description !== '')
</script>

<template>
    <Modal :show="ouvert" max-width="md" :aria-labelledby="idTitre" @close="emit('annuler')">
        <div class="p-6">
            <h2 :id="idTitre" class="text-text-main text-lg font-semibold">{{ titre }}</h2>

            <p v-if="decrit" class="text-text-muted mt-2 text-sm">{{ description }}</p>

            <div class="mt-6 flex justify-end gap-3">
                <!--
                    « Annuler » en `secondary` et l'action en `danger` : un cran
                    d'écart franc. Deux formulaires de l'application mettaient la
                    confirmation au même niveau que le refus, ce qui laisse
                    l'utilisateur choisir au hasard.
                -->
                <GlassButton variant="secondary" dusk="confirm-dialog-cancel" @click="emit('annuler')">
                    Annuler
                </GlassButton>
                <GlassButton
                    variant="danger"
                    dusk="confirm-dialog-confirm"
                    :loading="enCours"
                    @click="emit('confirmer')"
                >
                    {{ libelleConfirmation }}
                </GlassButton>
            </div>
        </div>
    </Modal>
</template>

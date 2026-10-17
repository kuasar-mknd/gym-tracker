<script setup>
import { ref, watch } from 'vue'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import {
    messageDeLaQuestionDeDeconnexion,
    questionDeDeconnexion,
    resterConnecte,
    seDeconnecterQuandMeme,
} from '@/composables/useDeconnexion'

/**
 * La question que pose une déconnexion quand des écritures faites hors ligne
 * n'ont pas encore été envoyées (#2050) : combien, et qu'elles seront perdues.
 *
 * La déconnexion acceptée efface la file du compte, pour que rien ne reste en
 * clair sur un appareil partagé. Elle ne le fait plus sans le dire :
 * `seDeconnecter` pose la question au lieu de partir, et seul « Se déconnecter
 * quand même » déconnecte. Le layout authentifié et le layout invité la
 * montent tous deux, comme leurs toasts : le menu et le profil sont sous le
 * premier, la vérification de l'adresse sous le second.
 *
 * Le nombre reste affiché pendant que la fenêtre se referme : effacé tout de
 * suite, la phrase disparaissait avant la fenêtre.
 */
const nombre = ref(0)

watch(
    questionDeDeconnexion,
    (question) => {
        if (question !== null) {
            nombre.value = question.nombre
        }
    },
    { immediate: true },
)
</script>

<template>
    <ConfirmDialog
        :ouvert="questionDeDeconnexion !== null"
        titre="Se déconnecter et perdre ces modifications ?"
        :description="messageDeLaQuestionDeDeconnexion(nombre)"
        libelle-annulation="Rester connecté"
        libelle-confirmation="Se déconnecter quand même"
        @annuler="resterConnecte"
        @confirmer="seDeconnecterQuandMeme"
    />
</template>

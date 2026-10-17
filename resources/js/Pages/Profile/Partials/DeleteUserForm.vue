<script setup>
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import Modal from '@/Components/UI/Modal.vue'
import { useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { detacherLAppareil } from '@/composables/useAbonnementPush'
import { effacerLesBrouillons } from '@/composables/useBrouillonsDeSeries'
import SyncService from '@/Utils/SyncService'
import { empreinteDuTemoin, oublierLesCopies } from '@/Utils/copiesHorsLigne'
import { siLaVisiteAboutit } from '@/Utils/miseAJourOptimiste'
import { effacerLaRecherche } from '@/Utils/rechercheDExercice'
import { SUPPRESSION_DU_COMPTE_NON_CONFIRMEE, direSansReseau } from '@/Utils/visitesHorsLigne'

const confirmingUserDeletion = ref(false)
const passwordInput = ref(null)

/**
 * La suppression est partie et sa réponse n'est pas revenue (#2048). La
 * fenêtre de confirmation le dit elle-même : le toast du layout resterait sous
 * elle, la fenêtre ouverte par `showModal()` passant au-dessus de toute la page.
 * Jamais « le compte n'est pas supprimé » : la requête a pu arriver.
 */
const suppressionSansReseau = ref(false)

const form = useForm({
    password: '',
})

const confirmUserDeletion = () => {
    confirmingUserDeletion.value = true
}

/**
 * Le serveur déconnecte le compte en le supprimant. L'appareil s'en détache
 * ensuite, comme à toute déconnexion (#1926), mais sans prévenir le serveur :
 * la session n'existe déjà plus. Rien avant la réponse, pour qu'un mot de passe
 * refusé ne coûte pas l'abonnement d'un compte qui reste.
 *
 * Ce que l'appareil gardait pour ce compte s'efface aussi : ses écritures
 * hors ligne encore en file, qui ne partiraient jamais, et ses refus, autres
 * onglets compris (#1964, #2050), les brouillons de séries et la dernière
 * recherche d'exercice (#2050). Le compte se lit avant la réponse, qui mène à
 * une page où plus personne n'est connecté. Une réponse qui recharge toute la
 * page (409 `X-Inertia-Location`, la version des actifs ayant changé depuis
 * l'ouverture de la page) arrive sans `onSuccess`, le compte pourtant
 * supprimé : l'effacement part aussi (`siLaVisiteAboutit`, #2077).
 *
 * Les copies hors ligne des pages, elles, s'oublient AVANT l'envoi (#2048,
 * décision 6 du propriétaire) : une réponse perdue ne laisse rien de lisible
 * sur l'appareil. Sans fermer la clé du témoin : un mot de passe refusé ne
 * coûte que des copies, que les pages suivantes de la session refont. Une
 * suppression acceptée la ferme ensuite, avec un second oubli : une réponse
 * en vol d'un autre onglet, partie avant elle, qui reposerait le témoin, ne
 * rouvre rien. La clé se relève avant l'envoi (`empreinteDuTemoin`) : la
 * réponse d'une suppression acceptée a déjà effacé le témoin quand
 * `onSuccess` arrive.
 */
const deleteUser = () => {
    const compteSupprime = SyncService.compte
    const cleDeLaSession = empreinteDuTemoin()
    suppressionSansReseau.value = false

    oublierLesCopies({ fermerLEtat: false })
    form.delete(
        route('profile.destroy'),
        siLaVisiteAboutit(
            () => {
                closeModal()
                oublierLesCopies({ fermerLeTemoin: true, fermerAussi: cleDeLaSession })
                detacherLAppareil({ prevenirLeServeur: false })
                SyncService.oublierLeCompte(compteSupprime)
                effacerLesBrouillons()
                effacerLaRecherche()
            },
            {
                preserveScroll: true,
                onError: () => passwordInput.value?.focus(),
                onNetworkError: direSansReseau(null, () => {
                    suppressionSansReseau.value = true
                }),
                onFinish: () => form.reset(),
            },
        ),
    )
}

const closeModal = () => {
    confirmingUserDeletion.value = false
    suppressionSansReseau.value = false
    form.clearErrors()
    form.reset()
}
</script>

<template>
    <GlassCard as="section" class="space-y-6">
        <header>
            <h2 class="titre-carte">Supprimer le compte</h2>
            <p class="text-text-muted mt-1 text-sm" data-testid="delete-account-promise">
                Une fois ton compte supprimé, tes données sont effacées de l'application. Des copies en restent un temps
                limité : quatorze jours dans les journaux du serveur, trente jours dans les rapports d'erreurs, 180
                jours dans l'historique d'audit et sept mois au plus dans les sauvegardes de la base. Une seconde copie
                des journaux, bornée par sa taille, peut aussi en garder des traces sans durée garantie, le temps que de
                nouvelles lignes les remplacent. Sur un autre appareil, les modifications faites hors ligne et jamais
                envoyées s'effacent à la première ouverture de l'application passé trente jours, et la dernière
                recherche d'exercice dès qu'une page de connexion s'y affiche.
            </p>
        </header>

        <GlassButton variant="danger" @click="confirmUserDeletion" data-testid="delete-account-button">
            Supprimer mon compte
        </GlassButton>

        <!--
          Rendered through Modal, which uses a native <dialog> opened with
          showModal(). That is what supplies the dialog role, aria-modal, the
          focus trap and Escape-to-close — none of which the hand-rolled overlay
          this replaced had. Deleting an account is the last screen where a
          keyboard user should be able to tab out into the page behind it.
        -->
        <Modal :show="confirmingUserDeletion" max-width="md" aria-labelledby="delete-account-title" @close="closeModal">
            <div class="p-6">
                <h2 id="delete-account-title" class="titre-carte">Confirmer la suppression</h2>

                <p class="text-text-muted mt-2 text-sm">
                    Cette action est irréversible. Entre ton mot de passe pour confirmer.
                </p>

                <p
                    v-if="suppressionSansReseau"
                    role="status"
                    class="bg-accent-danger/10 text-accent-danger-deep mt-4 rounded-xl p-3 text-sm font-bold"
                    data-testid="delete-account-offline"
                >
                    {{ SUPPRESSION_DU_COMPTE_NON_CONFIRMEE }} Réessaie quand le réseau sera revenu.
                </p>

                <div class="mt-4">
                    <GlassInput
                        v-model="form.password"
                        ref="passwordInput"
                        type="password"
                        label="Mot de passe"
                        hide-label
                        placeholder="Mot de passe"
                        :error="form.errors.password"
                        autocomplete="current-password"
                        @keyup.enter="deleteUser"
                        data-testid="delete-password-input"
                    />
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <GlassButton variant="secondary" @click="closeModal" data-testid="cancel-delete-button">
                        Annuler
                    </GlassButton>

                    <GlassButton
                        variant="danger"
                        :loading="form.processing"
                        @click="deleteUser"
                        data-testid="confirm-delete-button"
                    >
                        Supprimer
                    </GlassButton>
                </div>
            </div>
        </Modal>
    </GlassCard>
</template>

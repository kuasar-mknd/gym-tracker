<script setup>
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import Modal from '@/Components/UI/Modal.vue'
import { useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { detacherLAppareil } from '@/composables/useAbonnementPush'

const confirmingUserDeletion = ref(false)
const passwordInput = ref(null)

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
 */
const deleteUser = () => {
    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => {
            closeModal()
            detacherLAppareil({ prevenirLeServeur: false })
        },
        onError: () => passwordInput.value?.focus(),
        onFinish: () => form.reset(),
    })
}

const closeModal = () => {
    confirmingUserDeletion.value = false
    form.clearErrors()
    form.reset()
}
</script>

<template>
    <GlassCard as="section" class="space-y-6">
        <header>
            <h2 class="titre-carte">Supprimer le compte</h2>
            <p class="text-text-muted mt-1 text-sm" data-testid="delete-account-promise">
                Une fois ton compte supprimé, toutes tes données sont effacées de l'application. Seules les sauvegardes
                de la base en gardent une copie, le temps qu'elles expirent.
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

                <div class="mt-4">
                    <GlassInput
                        v-model="form.password"
                        ref="passwordInput"
                        type="password"
                        label="Mot de passe"
                        hide-label
                        placeholder="Mot de passe"
                        :error="form.errors.password"
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

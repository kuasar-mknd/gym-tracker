<script setup>
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import { useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

const confirmingUserDeletion = ref(false)
const passwordInput = ref(null)

const form = useForm({
    password: '',
})

const confirmUserDeletion = () => {
    confirmingUserDeletion.value = true
}

const deleteUser = () => {
    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
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
    <section class="space-y-6">
        <header>
            <h2 class="text-text-main text-lg font-semibold">Supprimer le compte</h2>
            <p class="text-text-muted mt-1 text-sm">
                Une fois ton compte supprimé, toutes tes données seront définitivement effacées.
            </p>
        </header>

        <GlassButton variant="danger" @click="confirmUserDeletion"> Supprimer mon compte </GlassButton>

        <!-- Deletion Modal -->
        <Teleport to="body">
            <div v-if="confirmingUserDeletion" class="glass-overlay animate-fade-in" @click.self="closeModal">
                <div
                    class="fixed inset-x-4 top-auto bottom-4 sm:inset-auto sm:top-1/2 sm:left-1/2 sm:w-full sm:max-w-md sm:-translate-x-1/2 sm:-translate-y-1/2"
                >
                    <div class="glass-modal animate-slide-up p-6">
                        <h2 class="text-text-main text-lg font-semibold">Confirmer la suppression</h2>

                        <p class="text-text-muted mt-2 text-sm">
                            Cette action est irréversible. Entre ton mot de passe pour confirmer.
                        </p>

                        <div class="mt-4">
                            <GlassInput
                                v-model="form.password"
                                ref="passwordInput"
                                type="password"
                                placeholder="Mot de passe"
                                :error="form.errors.password"
                                @keyup.enter="deleteUser"
                            />
                        </div>

                        <div class="mt-6 flex justify-end gap-3">
                            <GlassButton @click="closeModal"> Annuler </GlassButton>

                            <GlassButton variant="danger" :loading="form.processing" @click="deleteUser">
                                Supprimer
                            </GlassButton>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </section>
</template>

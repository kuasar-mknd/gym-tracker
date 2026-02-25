<script setup>
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import { useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

const passwordInput = ref(null)
const currentPasswordInput = ref(null)

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
})

const updatePassword = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () => {
            if (form.errors.password) {
                form.reset('password', 'password_confirmation')
                passwordInput.value?.focus()
            }
            if (form.errors.current_password) {
                form.reset('current_password')
                currentPasswordInput.value?.focus()
            }
        },
    })
}
</script>

<template>
    <section>
        <header>
            <h2 class="text-text-main text-lg font-semibold">Mot de passe</h2>
            <p class="text-text-muted mt-1 text-sm">
                Utilise un mot de passe long et unique pour sécuriser ton compte.
            </p>
        </header>

        <form @submit.prevent="updatePassword" class="mt-6 space-y-4">
            <GlassInput
                v-model="form.current_password"
                ref="currentPasswordInput"
                type="password"
                label="Mot de passe actuel"
                :error="form.errors.current_password"
                autocomplete="current-password"
            />

            <GlassInput
                v-model="form.password"
                ref="passwordInput"
                type="password"
                label="Nouveau mot de passe"
                :error="form.errors.password"
                autocomplete="new-password"
            />

            <GlassInput
                v-model="form.password_confirmation"
                type="password"
                label="Confirmer le mot de passe"
                :error="form.errors.password_confirmation"
                autocomplete="new-password"
            />

            <div class="flex items-center gap-4">
                <GlassButton type="submit" :loading="form.processing"> Mettre à jour </GlassButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p v-if="form.recentlySuccessful" class="text-accent-success text-sm">Enregistré ✓</p>
                </Transition>
            </div>
        </form>
    </section>
</template>

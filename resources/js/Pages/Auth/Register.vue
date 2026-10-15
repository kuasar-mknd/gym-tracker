<script setup>
import ConnexionParUnFournisseur from '@/Components/Auth/ConnexionParUnFournisseur.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { triggerHaptic } from '@/composables/useHaptics'
import { INSCRIPTION_NON_CONFIRMEE, direSansReseau } from '@/Utils/visitesHorsLigne'

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
})

const submit = () => {
    form.post(route('register'), {
        onSuccess: () => triggerHaptic('success'),
        onError: () => triggerHaptic('error'),
        onNetworkError: direSansReseau(INSCRIPTION_NON_CONFIRMEE),
        onFinish: () => form.reset('password', 'password_confirmation'),
    })
}
</script>

<template>
    <GuestLayout>
        <Head title="Inscription" />

        <div class="mb-6 text-center">
            <h2 class="titre-section">Bienvenue ! 💪</h2>
            <p class="text-text-muted mt-2">Crée ton compte pour commencer</p>
            <p class="text-text-muted mt-2 text-sm" data-testid="register-link-notice">
                Un lien partira à ton adresse : ton compte s’ouvre une fois ce lien suivi.
            </p>
        </div>

        <form @submit.prevent="submit" class="space-y-5">
            <GlassInput
                v-model="form.name"
                type="text"
                label="Nom"
                placeholder="Ton prénom"
                :error="form.errors.name"
                autocomplete="name"
                name="name"
                required
                autofocus
            />

            <GlassInput
                v-model="form.email"
                type="email"
                label="Email"
                placeholder="ton@email.com"
                :error="form.errors.email"
                autocomplete="username"
                name="email"
                required
            />

            <GlassInput
                v-model="form.password"
                type="password"
                label="Mot de passe"
                placeholder="••••••••"
                :error="form.errors.password"
                autocomplete="new-password"
                name="password"
                required
            />

            <GlassInput
                v-model="form.password_confirmation"
                type="password"
                label="Confirmer le mot de passe"
                placeholder="••••••••"
                :error="form.errors.password_confirmation"
                autocomplete="new-password"
                name="password_confirmation"
                required
            />

            <GlassButton
                type="submit"
                variant="primary"
                class="w-full"
                :loading="form.processing"
                :disabled="form.processing"
                data-testid="register-button"
            >
                Créer mon compte
            </GlassButton>

            <ConnexionParUnFournisseur verbe="S'inscrire avec" />
        </form>

        <template #footer>
            <p class="text-text-muted">
                Déjà inscrit ?
                <Link
                    :href="route('login')"
                    class="text-accent-primary-deep hover:text-accent-tertiary-deep font-bold transition-colors"
                    >Se connecter</Link
                >
            </p>
        </template>
    </GuestLayout>
</template>

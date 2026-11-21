<script setup>
import Checkbox from '@/Components/Form/Checkbox.vue'
import ConnexionParUnFournisseur from '@/Components/Auth/ConnexionParUnFournisseur.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { triggerHaptic } from '@/composables/useHaptics'
import { CONNEXION_SANS_RESEAU, direSansReseau } from '@/Utils/visitesHorsLigne'

defineProps({
    canResetPassword: Boolean,
    status: String,
})

const form = useForm({
    email: '',
    password: '',
    remember: false,
})

const submit = () => {
    form.post(route('login'), {
        onSuccess: () => triggerHaptic('success'),
        onError: () => triggerHaptic('error'),
        onNetworkError: direSansReseau(CONNEXION_SANS_RESEAU),
        onFinish: () => form.reset('password'),
    })
}
</script>

<template>
    <GuestLayout>
        <Head title="Connexion" />

        <div class="mb-6 text-center">
            <h2 class="titre-ecran">Connexion</h2>
        </div>

        <div v-if="status" class="state-fill border-accent-state mb-4 rounded-xl border p-3 text-sm font-medium">
            {{ status }}
        </div>

        <form @submit.prevent="submit" class="space-y-5">
            <GlassInput
                v-model="form.email"
                type="email"
                name="email"
                label="Email"
                placeholder="ton@email.com"
                :error="form.errors.email"
                autocomplete="username"
                required
                autofocus
                data-testid="email-input"
            />

            <GlassInput
                v-model="form.password"
                type="password"
                name="password"
                label="Mot de passe"
                placeholder="••••••••"
                :error="form.errors.password"
                autocomplete="current-password"
                required
                data-testid="password-input"
            />

            <div class="flex items-center justify-between">
                <label class="flex cursor-pointer items-center">
                    <Checkbox :checked="form.remember" @update:checked="(val) => (form.remember = val)" />
                    <span class="text-text-muted ml-2 text-sm font-medium">Se souvenir</span>
                </label>

                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="text-accent-primary-deep text-sm font-medium hover:underline"
                >
                    Mot de passe oublié ?
                </Link>
            </div>

            <GlassButton
                type="submit"
                variant="primary"
                class="w-full"
                :loading="form.processing"
                :disabled="form.processing"
                data-testid="login-button"
            >
                Se connecter
            </GlassButton>

            <ConnexionParUnFournisseur verbe="Continuer avec" />
        </form>

        <template #footer>
            <p class="text-text-muted">
                Pas encore de compte ?
                <Link :href="route('register')" class="text-accent-primary-deep font-medium hover:underline"
                    >Créer un compte</Link
                >
            </p>

            <!--
              Raccourci de connexion pour le développement mobile. Conditionné à la même
              chose que la route elle-même : app()->environment('local'). Le
              verrou est côté serveur, pas côté build — import.meta.env.DEV
              vaut false dans le build que le serveur local sert lui aussi.
            -->
            <p v-if="$page.props.is_local" class="mt-4 text-center">
                <a href="/__dev-login" class="text-text-muted text-xs underline">Connexion dev</a>
            </p>
        </template>
    </GuestLayout>
</template>

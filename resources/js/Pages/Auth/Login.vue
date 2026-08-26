<script setup>
import Checkbox from '@/Components/Form/Checkbox.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { triggerHaptic } from '@/composables/useHaptics'

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
        onFinish: () => form.reset('password'),
    })
}
</script>

<template>
    <GuestLayout>
        <Head title="Connexion" />

        <div class="mb-6 text-center">
            <h2 class="font-display text-text-main text-2xl font-black tracking-tight uppercase italic">
                Bon retour ! 👋
            </h2>
            <p class="text-text-muted mt-2">Connecte-toi pour continuer</p>
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
                    class="text-accent-primary-deep hover:text-accent-tertiary text-sm font-semibold transition-colors"
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

            <!-- Social Login -->
            <div class="my-6 flex items-center justify-center gap-4 text-sm">
                <div class="bg-border h-px flex-1"></div>
                <span
                    class="text-text-muted border-surface-card/50 bg-surface-card/60 rounded-full border px-4 py-1 font-medium backdrop-blur-md"
                >
                    Ou continuer avec
                </span>
                <div class="bg-border h-px flex-1"></div>
            </div>

            <div class="flex justify-center gap-4">
                <a
                    v-if="$page.props.social_login_enabled?.google"
                    :href="route('social.redirect', 'google')"
                    class="border-surface-sunken bg-surface-card flex h-12 w-12 items-center justify-center rounded-xl border shadow-sm transition-all hover:scale-105 hover:shadow-md active:scale-95"
                    aria-label="Continuer avec Google"
                >
                    <img
                        src="https://www.svgrepo.com/show/475656/google-color.svg"
                        loading="lazy"
                        class="h-6 w-6"
                        alt="Google"
                    />
                </a>
                <a
                    v-if="$page.props.social_login_enabled?.github"
                    :href="route('social.redirect', 'github')"
                    class="border-text-main bg-text-main flex h-12 w-12 items-center justify-center rounded-xl border shadow-sm transition-all hover:scale-105 hover:shadow-md active:scale-95"
                    aria-label="Continuer avec GitHub"
                >
                    <img
                        src="https://www.svgrepo.com/show/512317/github-142.svg"
                        loading="lazy"
                        class="h-6 w-6 invert"
                        alt="GitHub"
                    />
                </a>
                <a
                    v-if="$page.props.social_login_enabled?.apple"
                    :href="route('social.redirect', 'apple')"
                    class="border-text-main bg-text-main flex h-12 w-12 items-center justify-center rounded-xl border shadow-sm transition-all hover:scale-105 hover:shadow-md active:scale-95"
                    aria-label="Continuer avec Apple"
                >
                    <img
                        src="https://www.svgrepo.com/show/511330/apple-173.svg"
                        loading="lazy"
                        class="h-6 w-6 invert"
                        alt="Apple"
                    />
                </a>
            </div>
        </form>

        <template #footer>
            <p class="text-text-muted">
                Pas encore de compte ?
                <Link
                    :href="route('register')"
                    class="text-accent-primary-deep hover:text-accent-tertiary font-bold transition-colors"
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
                <a href="/__dev-login" class="text-text-muted text-xs font-bold underline">Connexion dev</a>
            </p>
        </template>
    </GuestLayout>
</template>

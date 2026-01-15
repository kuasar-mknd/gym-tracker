<script setup>
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'

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
        onFinish: () => form.reset('password'),
    })
}
</script>

<template>
    <GuestLayout>
        <Head title="Connexion" />

        <div class="mb-6 text-center">
            <h2 class="text-2xl font-bold text-white">Bon retour ! 👋</h2>
            <p class="mt-1 text-white/60">Connecte-toi pour continuer</p>
        </div>

        <div v-if="status" class="mb-4 rounded-xl bg-accent-success/20 p-3 text-sm text-accent-success">
            {{ status }}
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <GlassInput
                v-model="form.email"
                type="email"
                label="Email"
                placeholder="ton@email.com"
                :error="form.errors.email"
                autocomplete="username"
                required
                autofocus
            />

            <GlassInput
                v-model="form.password"
                type="password"
                label="Mot de passe"
                placeholder="••••••••"
                :error="form.errors.password"
                autocomplete="current-password"
                required
            />

            <div class="flex items-center justify-between">
                <label class="flex cursor-pointer items-center">
                    <input
                        type="checkbox"
                        v-model="form.remember"
                        class="h-5 w-5 rounded border-glass-border bg-glass text-accent-primary focus:ring-2 focus:ring-accent-primary focus:ring-offset-0"
                    />
                    <span class="ml-2 text-sm text-white/70">Se souvenir</span>
                </label>

                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="text-sm text-accent-primary hover:underline"
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
            >
                Se connecter
            </GlassButton>

            <!-- Social Login -->
            <div class="relative my-6">
                <div class="absolute inset-0 flex items-center">
                    <div class="w-full border-t border-glass-border"></div>
                </div>
                <div class="relative flex justify-center text-sm">
                    <span class="rounded bg-black/40 px-3 py-0.5 text-white/50 backdrop-blur-sm"
                        >Ou continuer avec</span
                    >
                </div>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <a
                    v-if="$page.props.social_login_enabled?.google ?? true"
                    :href="route('social.redirect', 'google')"
                    class="bg-glass-surface flex h-11 w-11 items-center justify-center rounded-xl border border-glass-border transition hover:bg-white/10"
                >
                    <img
                        src="https://www.svgrepo.com/show/475656/google-color.svg"
                        loading="lazy"
                        class="h-5 w-5"
                        alt="Google"
                    />
                </a>
                <a
                    v-if="$page.props.social_login_enabled?.github ?? true"
                    :href="route('social.redirect', 'github')"
                    class="bg-glass-surface flex h-11 w-11 items-center justify-center rounded-xl border border-glass-border transition hover:bg-white/10"
                >
                    <img
                        src="https://www.svgrepo.com/show/512317/github-142.svg"
                        loading="lazy"
                        class="h-5 w-5 invert"
                        alt="GitHub"
                    />
                </a>
                <a
                    v-if="$page.props.social_login_enabled?.apple ?? true"
                    :href="route('social.redirect', 'apple')"
                    class="bg-glass-surface flex h-11 w-11 items-center justify-center rounded-xl border border-glass-border transition hover:bg-white/10"
                >
                    <img
                        src="https://www.svgrepo.com/show/511330/apple-173.svg"
                        loading="lazy"
                        class="h-5 w-5 invert"
                        alt="Apple"
                    />
                </a>
            </div>
        </form>

        <template #footer>
            <p>
                Pas encore de compte ?
                <Link :href="route('register')" class="text-accent-primary hover:underline"> Créer un compte </Link>
            </p>
        </template>
    </GuestLayout>
</template>

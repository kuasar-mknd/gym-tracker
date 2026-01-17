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
            <h2 class="font-display text-2xl font-black uppercase italic tracking-tight text-text-main">
                Bon retour ! 👋
            </h2>
            <p class="mt-2 text-text-muted">Connecte-toi pour continuer</p>
        </div>

        <div
            v-if="status"
            class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-medium text-emerald-700"
        >
            {{ status }}
        </div>

        <form @submit.prevent="submit" class="space-y-5">
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
                        class="h-5 w-5 rounded-lg border-slate-300 bg-white text-electric-orange focus:ring-2 focus:ring-electric-orange/30 focus:ring-offset-0"
                    />
                    <span class="ml-2 text-sm font-medium text-text-muted">Se souvenir</span>
                </label>

                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="text-sm font-semibold text-electric-orange transition-colors hover:text-vivid-violet"
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
                    <div class="w-full border-t border-slate-200"></div>
                </div>
                <div class="relative flex justify-center text-sm">
                    <span class="rounded-full bg-white px-4 py-1 font-medium text-text-muted"> Ou continuer avec </span>
                </div>
            </div>

            <div class="flex justify-center gap-4">
                <a
                    v-if="$page.props.social_login_enabled?.google ?? true"
                    :href="route('social.redirect', 'google')"
                    class="flex h-12 w-12 items-center justify-center rounded-xl border border-slate-200 bg-white shadow-sm transition-all hover:scale-105 hover:shadow-md active:scale-95"
                >
                    <img
                        src="https://www.svgrepo.com/show/475656/google-color.svg"
                        loading="lazy"
                        class="h-6 w-6"
                        alt="Google"
                    />
                </a>
                <a
                    v-if="$page.props.social_login_enabled?.github ?? true"
                    :href="route('social.redirect', 'github')"
                    class="flex h-12 w-12 items-center justify-center rounded-xl border border-slate-800 bg-slate-900 shadow-sm transition-all hover:scale-105 hover:shadow-md active:scale-95"
                >
                    <img
                        src="https://www.svgrepo.com/show/512317/github-142.svg"
                        loading="lazy"
                        class="h-6 w-6 invert"
                        alt="GitHub"
                    />
                </a>
                <a
                    v-if="$page.props.social_login_enabled?.apple ?? true"
                    :href="route('social.redirect', 'apple')"
                    class="flex h-12 w-12 items-center justify-center rounded-xl border border-slate-700 bg-black shadow-sm transition-all hover:scale-105 hover:shadow-md active:scale-95"
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
                    class="font-bold text-electric-orange transition-colors hover:text-vivid-violet"
                    >Créer un compte</Link
                >
            </p>
        </template>
    </GuestLayout>
</template>

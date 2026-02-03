<script setup>
import Checkbox from '@/Components/Checkbox.vue'
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
                name="email"
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
                name="password"
                required
            />

            <div class="flex items-center justify-between">
                <label class="flex cursor-pointer items-center">
                    <Checkbox :checked="form.remember" @update:checked="(val) => (form.remember = val)" />
                    <span class="text-text-muted ml-2 text-sm font-medium">Se souvenir</span>
                </label>

                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="text-electric-orange hover:text-vivid-violet text-sm font-semibold transition-colors"
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
                data-testid="login-submit"
            >
                Se connecter
            </GlassButton>

            <!-- Social Login -->
            <div class="my-6 flex items-center justify-center gap-4 text-sm">
                <div class="h-px flex-1 bg-slate-200"></div>
                <span
                    class="text-text-muted rounded-full border border-white/50 bg-white/60 px-4 py-1 font-medium backdrop-blur-md"
                >
                    Ou continuer avec
                </span>
                <div class="h-px flex-1 bg-slate-200"></div>
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
                    class="text-electric-orange hover:text-vivid-violet font-bold transition-colors"
                    >Créer un compte</Link
                >
            </p>
        </template>
    </GuestLayout>
</template>

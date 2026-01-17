<script setup>
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
})

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    })
}
</script>

<template>
    <GuestLayout>
        <Head title="Inscription" />

        <div class="mb-6 text-center">
            <h2 class="font-display text-2xl font-black uppercase italic tracking-tight text-text-main">
                Bienvenue ! 💪
            </h2>
            <p class="mt-2 text-text-muted">Crée ton compte pour commencer</p>
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
            >
                Créer mon compte
            </GlassButton>

            <!-- Social Login -->
            <div class="relative my-6">
                <div class="absolute inset-0 flex items-center">
                    <div class="w-full border-t border-slate-200"></div>
                </div>
                <div class="relative flex justify-center text-sm">
                    <span class="rounded-full bg-white px-4 py-1 font-medium text-text-muted">
                        Ou s'inscrire avec
                    </span>
                </div>
            </div>

            <div class="flex justify-center gap-4">
                <a
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
                Déjà inscrit ?
                <Link
                    :href="route('login')"
                    class="font-bold text-electric-orange transition-colors hover:text-vivid-violet"
                    >Se connecter</Link
                >
            </p>
        </template>
    </GuestLayout>
</template>

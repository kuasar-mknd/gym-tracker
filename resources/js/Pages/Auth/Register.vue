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
            <h2 class="text-2xl font-bold text-white">Bienvenue ! 💪</h2>
            <p class="mt-1 text-white/60">Crée ton compte pour commencer</p>
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <GlassInput
                v-model="form.name"
                type="text"
                label="Nom"
                placeholder="Ton prénom"
                :error="form.errors.name"
                autocomplete="name"
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
                required
            />

            <GlassInput
                v-model="form.password"
                type="password"
                label="Mot de passe"
                placeholder="••••••••"
                :error="form.errors.password"
                autocomplete="new-password"
                required
            />

            <GlassInput
                v-model="form.password_confirmation"
                type="password"
                label="Confirmer le mot de passe"
                placeholder="••••••••"
                :error="form.errors.password_confirmation"
                autocomplete="new-password"
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
        </form>

        <template #footer>
            <p>
                Déjà inscrit ?
                <Link :href="route('login')" class="text-accent-primary hover:underline"> Se connecter </Link>
            </p>
        </template>
    </GuestLayout>
</template>

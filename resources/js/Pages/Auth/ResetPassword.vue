<script setup>
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import { Head, useForm } from '@inertiajs/vue3'

const props = defineProps({
    email: String,
    token: String,
})

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
})

const submit = () => {
    form.post(route('password.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    })
}
</script>

<template>
    <GuestLayout>
        <Head title="Réinitialiser le mot de passe" />

        <div class="mb-6 text-center">
            <h2 class="text-2xl font-bold text-white">Nouveau mot de passe</h2>
            <p class="mt-1 text-white/60">Choisis un nouveau mot de passe sécurisé</p>
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <GlassInput
                v-model="form.email"
                type="email"
                label="Email"
                :error="form.errors.email"
                autocomplete="username"
                required
            />

            <GlassInput
                v-model="form.password"
                type="password"
                label="Nouveau mot de passe"
                placeholder="••••••••"
                :error="form.errors.password"
                autocomplete="new-password"
                required
                autofocus
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

            <GlassButton type="submit" variant="primary" class="w-full" :loading="form.processing">
                Réinitialiser
            </GlassButton>
        </form>
    </GuestLayout>
</template>

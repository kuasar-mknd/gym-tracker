<script setup>
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import { Head, useForm } from '@inertiajs/vue3'

defineProps({
    status: String,
})

const form = useForm({
    email: '',
})

const submit = () => {
    form.post(route('password.email'))
}
</script>

<template>
    <GuestLayout>
        <Head title="Mot de passe oublié" />

        <div class="mb-6 text-center">
            <h2 class="text-2xl font-bold text-white">Mot de passe oublié ?</h2>
            <p class="mt-2 text-sm text-white/60">Entre ton email et nous t'enverrons un lien de réinitialisation.</p>
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
                required
                autofocus
            />

            <GlassButton type="submit" variant="primary" class="w-full" :loading="form.processing">
                Envoyer le lien
            </GlassButton>
        </form>
    </GuestLayout>
</template>

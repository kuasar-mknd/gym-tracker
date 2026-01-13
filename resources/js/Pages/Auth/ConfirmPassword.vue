<script setup>
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import { Head, useForm } from '@inertiajs/vue3'

const form = useForm({
    password: '',
})

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => form.reset(),
    })
}
</script>

<template>
    <GuestLayout>
        <Head title="Confirmer le mot de passe" />

        <div class="mb-6 text-center">
            <h2 class="text-2xl font-bold text-white">Confirmation requise</h2>
            <p class="mt-2 text-sm text-white/60">
                Cette zone est sécurisée. Confirme ton mot de passe pour continuer.
            </p>
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <GlassInput
                v-model="form.password"
                type="password"
                label="Mot de passe"
                placeholder="••••••••"
                :error="form.errors.password"
                autocomplete="current-password"
                required
                autofocus
            />

            <GlassButton type="submit" variant="primary" class="w-full" :loading="form.processing">
                Confirmer
            </GlassButton>
        </form>
    </GuestLayout>
</template>

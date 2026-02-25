<script setup>
import GlassButton from '@/Components/UI/GlassButton.vue'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

const props = defineProps({
    status: String,
})

const form = useForm({})

const submit = () => {
    form.post(route('verification.send'))
}

const verificationLinkSent = computed(() => props.status === 'verification-link-sent')
</script>

<template>
    <GuestLayout>
        <Head title="Vérifier l'email" />

        <div class="mb-6 text-center">
            <div class="mb-4 text-5xl">📧</div>
            <h2 class="text-text-main text-2xl font-bold">Vérifie ton email</h2>
            <p class="text-text-muted mt-2 text-sm">
                Merci de ton inscription ! Clique sur le lien dans l'email que nous t'avons envoyé.
            </p>
        </div>

        <div v-if="verificationLinkSent" class="bg-plate-green/20 text-plate-green mb-4 rounded-xl p-3 text-sm">
            Un nouveau lien de vérification a été envoyé à ton adresse email.
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <GlassButton type="submit" variant="primary" class="w-full" :loading="form.processing">
                Renvoyer l'email
            </GlassButton>

            <Link :href="route('logout')" method="post" as="button" class="glass-button w-full justify-center">
                Se déconnecter
            </Link>
        </form>
    </GuestLayout>
</template>

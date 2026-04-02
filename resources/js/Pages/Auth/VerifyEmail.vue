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

        <div
            v-if="verificationLinkSent"
            class="mb-4 rounded-2xl border border-emerald-500/20 bg-emerald-500/10 p-4 text-sm font-medium text-emerald-600 shadow-sm backdrop-blur-md"
        >
            Un nouveau lien de vérification a été envoyé à ton adresse email.
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <GlassButton type="submit" variant="primary" class="w-full" :loading="form.processing">
                Renvoyer l'email
            </GlassButton>

            <Link
                :href="route('logout')"
                method="post"
                as="button"
                class="text-text-main flex w-full items-center justify-center rounded-2xl border border-white/20 bg-white/10 px-4 py-3 text-sm font-bold shadow-sm backdrop-blur-md transition-all duration-300 hover:-translate-y-0.5 hover:bg-white/20 hover:shadow-md active:scale-95"
            >
                Se déconnecter
            </Link>
        </form>
    </GuestLayout>
</template>

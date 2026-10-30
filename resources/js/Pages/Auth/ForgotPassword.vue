<script setup>
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { ENVOI_DU_LIEN_NON_CONFIRME, direSansReseau } from '@/Utils/visitesHorsLigne'

defineProps({
    status: String,
})

const form = useForm({
    email: '',
})

const submit = () => {
    form.post(route('password.email'), { onNetworkError: direSansReseau(ENVOI_DU_LIEN_NON_CONFIRME) })
}
</script>

<template>
    <GuestLayout>
        <Head title="Mot de passe oublié" />

        <div class="mb-6 text-center">
            <h2 class="titre-section">Mot de passe oublié ?</h2>
            <p class="text-text-muted mt-2 text-sm">Entre ton email et nous t'enverrons un lien de réinitialisation.</p>
        </div>

        <div v-if="status" class="bg-accent-state/20 text-accent-state-deep mb-4 rounded-xl p-3 text-sm">
            {{ status }}
        </div>

        <!--
            L'adresse part telle qu'elle est tapée, et le serveur en juge (#2104) :
            le profil promet le lien à une adresse que la messagerie écrit et que
            la règle `email` admet, et le serveur cherche le compte sous l'adresse
            reçue. Un champ `type="email"` ne l'envoyait pas toujours ainsi. Le
            navigateur le juge selon la spécification HTML, plus étroite que la
            règle du serveur (partie locale entre guillemets, domaine littéral),
            et Chromium y convertit en Punycode un domaine internationalisé. Le
            champ est donc un champ texte au clavier d'adresse (`inputmode`), et
            le formulaire porte `novalidate`, qui laisse aussi au serveur un
            champ vide : son refus s'affiche sous le champ.
        -->
        <form novalidate @submit.prevent="submit" class="space-y-4">
            <GlassInput
                v-model="form.email"
                type="text"
                inputmode="email"
                autocomplete="email"
                autocapitalize="none"
                spellcheck="false"
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

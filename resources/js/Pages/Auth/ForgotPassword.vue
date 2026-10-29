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
            `novalidate` : le navigateur juge un champ `type="email"` selon la
            spécification HTML, plus étroite que la règle `email` du serveur, et
            n'envoyait pas une adresse que la messagerie écrit et que le profil
            promet au lien (partie locale entre guillemets, domaine littéral).
            Le serveur juge l'adresse, et son refus s'affiche sous le champ (#2104).
        -->
        <form novalidate @submit.prevent="submit" class="space-y-4">
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

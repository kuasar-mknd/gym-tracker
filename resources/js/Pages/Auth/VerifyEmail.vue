<script setup>
import GlassButton from '@/Components/UI/GlassButton.vue'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'
import { seDeconnecter } from '@/composables/useDeconnexion'
import { ENVOI_DU_LIEN_NON_CONFIRME, direSansReseau } from '@/Utils/visitesHorsLigne'

const props = defineProps({
    status: String,
})

const form = useForm({})

const submit = () => {
    form.post(route('verification.send'), { onNetworkError: direSansReseau(ENVOI_DU_LIEN_NON_CONFIRME) })
}

const verificationLinkSent = computed(() => props.status === 'verification-link-sent')

/**
 * Le serveur n'envoie aucun lien à une adresse que sa messagerie ne sait pas
 * écrire, qu'un compte ancien peut porter (#2075) : il le dit, et le profil,
 * ouvert sans adresse vérifiée, permet d'en changer.
 */
const adresseQueLaMessagerieNEcritPas = computed(() => props.status === 'adresse-que-la-messagerie-n-ecrit-pas')
</script>

<template>
    <GuestLayout>
        <Head title="Vérifier l'email" />

        <div class="mb-6 text-center">
            <div class="mb-4 text-5xl">📧</div>
            <h2 class="titre-section">Vérifie ton email</h2>
            <p class="text-text-muted mt-2 text-sm">
                Merci de ton inscription ! Clique sur le lien dans l'email que nous t'avons envoyé.
            </p>
        </div>

        <div v-if="verificationLinkSent" class="bg-accent-state/20 text-accent-state-deep mb-4 rounded-xl p-3 text-sm">
            Un nouveau lien de vérification a été envoyé à ton adresse email.
        </div>

        <div
            v-if="adresseQueLaMessagerieNEcritPas"
            class="bg-accent-warning/20 text-accent-warning-deep mb-4 rounded-xl p-3 text-sm"
            role="status"
            data-testid="adresse-que-la-messagerie-n-ecrit-pas"
        >
            Notre messagerie ne sait pas écrire à ton adresse : aucun lien ne peut lui parvenir. Change-la depuis
            <Link :href="route('profile.edit')" class="underline hover:no-underline">ton profil</Link>, le lien partira
            à la nouvelle.
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <GlassButton type="submit" variant="primary" class="w-full" :loading="form.processing">
                Renvoyer l'email
            </GlassButton>

            <!-- Détache l'appareil du compte avant de partir (#1926) ; ne soumet pas le renvoi. -->
            <GlassButton type="button" class="w-full justify-center" @click="seDeconnecter">
                Se déconnecter
            </GlassButton>
        </form>
    </GuestLayout>
</template>

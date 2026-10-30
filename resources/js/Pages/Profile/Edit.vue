<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import DeleteUserForm from './Partials/DeleteUserForm.vue'
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue'
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue'
import UpdateNotificationPreferencesForm from './Partials/UpdateNotificationPreferencesForm.vue'
import { Head } from '@inertiajs/vue3'

const props = defineProps({
    mustVerifyEmail: Boolean,
    status: String,
    fournisseurDeConnexion: {
        type: String,
        default: null,
    },
    peutRecevoirLeLienDeReinitialisation: {
        type: Boolean,
        default: true,
    },
    adresseVerifiee: Boolean,
    notificationPreferences: Object,
    hasPushSubscription: Boolean,
})
</script>

<template>
    <Head title="Mon Profil" />

    <AuthenticatedLayout page-title="Mon Profil" show-back back-route="profile.index">
        <template #header>
            <h1 class="titre-carte">Mon Profil</h1>
        </template>

        <div class="space-y-6">
            <!-- Profile Info -->
            <GlassCard class="animate-slide-up">
                <UpdateProfileInformationForm
                    :must-verify-email="mustVerifyEmail"
                    :status="status"
                    :fournisseur-de-connexion="fournisseurDeConnexion"
                    :peut-recevoir-le-lien-de-reinitialisation="peutRecevoirLeLienDeReinitialisation"
                    :adresse-verifiee="adresseVerifiee"
                />
            </GlassCard>

            <!-- Password -->
            <GlassCard class="stagger-2 animate-slide-up">
                <UpdatePasswordForm
                    :fournisseur-de-connexion="fournisseurDeConnexion"
                    :peut-recevoir-le-lien-de-reinitialisation="peutRecevoirLeLienDeReinitialisation"
                />
            </GlassCard>

            <!-- Notification Preferences -->
            <GlassCard class="stagger-3 animate-slide-up">
                <UpdateNotificationPreferencesForm
                    :preferences="notificationPreferences"
                    :has-push-subscription="props.hasPushSubscription"
                />
            </GlassCard>

            <!-- Delete Account -->
            <GlassCard class="stagger-4 animate-slide-up">
                <DeleteUserForm
                    :fournisseur-de-connexion="fournisseurDeConnexion"
                    :peut-recevoir-le-lien-de-reinitialisation="peutRecevoirLeLienDeReinitialisation"
                />
            </GlassCard>
        </div>
    </AuthenticatedLayout>
</template>

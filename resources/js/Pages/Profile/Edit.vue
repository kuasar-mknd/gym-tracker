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
    notificationPreferences: Object,
    hasPushSubscription: Boolean,
})
</script>

<template>
    <Head title="Mon Profil" />

    <AuthenticatedLayout page-title="Mon Profil" show-back back-route="profile.index">
        <template #header>
            <h2 class="text-text-main text-xl font-semibold">Mon Profil</h2>
        </template>

        <div class="space-y-6">
            <!-- Profile Info -->
            <GlassCard class="animate-slide-up">
                <UpdateProfileInformationForm :must-verify-email="mustVerifyEmail" :status="status" />
            </GlassCard>

            <!-- Password -->
            <GlassCard class="stagger-2 animate-slide-up">
                <UpdatePasswordForm />
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
                <DeleteUserForm />
            </GlassCard>
        </div>
    </AuthenticatedLayout>
</template>

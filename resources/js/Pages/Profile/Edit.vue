<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import DeleteUserForm from './Partials/DeleteUserForm.vue'
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue'
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue'
import UpdateNotificationPreferencesForm from './Partials/UpdateNotificationPreferencesForm.vue'
import { Head } from '@inertiajs/vue3'

defineProps({
    mustVerifyEmail: Boolean,
    status: String,
    notificationPreferences: Object,
})
</script>

<template>
    <Head title="Mon Profil" />

    <AuthenticatedLayout page-title="Mon Profil" show-back back-route="profile.index">
        <template #header>
            <h2 class="text-xl font-semibold text-text-main">Mon Profil</h2>
        </template>

        <div class="space-y-6">
            <!-- Profile Info -->
            <GlassCard class="animate-slide-up">
                <UpdateProfileInformationForm :must-verify-email="mustVerifyEmail" :status="status" />
            </GlassCard>

            <!-- Password -->
            <GlassCard class="animate-slide-up" style="animation-delay: 0.1s">
                <UpdatePasswordForm />
            </GlassCard>

            <!-- Notification Preferences -->
            <GlassCard class="animate-slide-up" style="animation-delay: 0.15s">
                <UpdateNotificationPreferencesForm :preferences="notificationPreferences" />
            </GlassCard>

            <!-- Delete Account -->
            <GlassCard class="animate-slide-up" style="animation-delay: 0.2s">
                <DeleteUserForm />
            </GlassCard>
        </div>
    </AuthenticatedLayout>
</template>

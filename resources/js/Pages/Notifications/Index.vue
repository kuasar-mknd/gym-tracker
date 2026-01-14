<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import { Head, Link, router } from '@inertiajs/vue3'

const props = defineProps({
    notifications: Object,
})

const markAsRead = (id) => {
    router.post(
        route('notifications.mark-as-read', id),
        {},
        {
            preserveScroll: true,
        },
    )
}

const markAllAsRead = () => {
    router.post(
        route('notifications.mark-all-as-read'),
        {},
        {
            preserveScroll: true,
        },
    )
}

const formatDate = (date) => {
    return new Date(date).toLocaleDateString('fr-FR', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    })
}
</script>

<template>
    <Head title="Notifications" />

    <AuthenticatedLayout page-title="Notifications">
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-white">Notifications</h2>
                <GlassButton
                    v-if="notifications.data.some((n) => !n.read_at)"
                    @click="markAllAsRead"
                    class="!py-1.5 !text-xs"
                >
                    Tout marquer comme lu
                </GlassButton>
            </div>
        </template>

        <div class="space-y-4">
            <div
                v-if="notifications.data.length === 0"
                class="flex flex-col items-center justify-center py-12 text-center"
            >
                <div class="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-glass text-white/20">
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-8 w-8"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"
                        />
                    </svg>
                </div>
                <h3 class="text-lg font-medium text-white">Aucune notification</h3>
                <p class="mt-1 text-white/50">Tu es à jour !</p>
            </div>

            <div v-else class="space-y-3">
                <GlassCard
                    v-for="notification in notifications.data"
                    :key="notification.id"
                    :class="['transition', !notification.read_at ? 'ring-1 ring-accent-primary/30' : 'opacity-70']"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex gap-4">
                            <div
                                :class="[
                                    'mt-1 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl',
                                    notification.data.type === 'personal_record'
                                        ? 'bg-yellow-500/20 text-yellow-500'
                                        : 'bg-blue-500/20 text-blue-500',
                                ]"
                            >
                                <svg
                                    v-if="notification.data.type === 'personal_record'"
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="h-6 w-6"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"
                                    />
                                </svg>
                                <svg
                                    v-else
                                    xmlns="http://www.w3.org/2000/svg"
                                    class="h-6 w-6"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
                                    />
                                </svg>
                            </div>
                            <div>
                                <h4 class="font-semibold text-white">{{ notification.data.title }}</h4>
                                <p class="text-sm text-white/70">{{ notification.data.message }}</p>
                                <span class="mt-2 block text-[10px] uppercase tracking-wider text-white/30">
                                    {{ formatDate(notification.created_at) }}
                                </span>
                            </div>
                        </div>

                        <button
                            v-if="!notification.read_at"
                            @click="markAsRead(notification.id)"
                            class="rounded-lg p-1 text-white/20 hover:bg-white/5 hover:text-white"
                            title="Marquer comme lu"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-5 w-5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M5 13l4 4L19 7"
                                />
                            </svg>
                        </button>
                    </div>
                </GlassCard>
            </div>

            <!-- Pagination (if needed) -->
            <div v-if="notifications.links.length > 3" class="mt-6 flex justify-center">
                <!-- Add simple pagination here if required -->
            </div>
        </div>
    </AuthenticatedLayout>
</template>

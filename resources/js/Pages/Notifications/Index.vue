<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import { Head, router } from '@inertiajs/vue3'
import GlassIconButton from '@/Components/UI/GlassIconButton.vue'

defineProps({
    notifications: Object,
})

const markAsRead = (id) => {
    router.post(
        route('notifications.mark-as-read', { id: id }),
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

/**
 * Pagination was a conditional empty div wrapping a "add this if required"
 * comment, so past the first 20 notifications the rest were unreachable.
 *
 * Prev/next rather than numbered links: this is a phone-first screen, and two
 * thumb-sized targets beat a row of small ones.
 */
const goToPage = (url) => {
    if (url) {
        router.visit(url)
    }
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
                <h2 class="text-text-main text-xl font-semibold">Notifications</h2>
                <GlassButton
                    v-if="notifications.data.some((n) => !n.read_at)"
                    @click="markAllAsRead"
                    class="py-1.5! text-xs!"
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
                <div
                    class="text-text-muted/20 bg-surface-sunken mb-4 flex h-16 w-16 items-center justify-center rounded-full"
                >
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
                <h3 class="text-text-main text-lg font-medium">Aucune notification</h3>
                <p class="text-text-muted mt-1">Tu es à jour !</p>
            </div>

            <div v-else class="space-y-3">
                <GlassCard
                    v-for="notification in notifications.data"
                    :key="notification.id"
                    :data-notification-id="notification.id"
                    :class="['transition', !notification.read_at ? 'ring-accent-primary/30 ring-1' : 'opacity-70']"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex gap-4">
                            <div
                                :class="[
                                    'mt-1 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl',
                                    /*
                                     * En aplat avec de l'encre, et non en texte
                                     * colore sur son propre lavis : ce motif-la
                                     * rendait 2,70:1 pour le rose et 1,36:1
                                     * pour le cyan, illisible dans les deux cas.
                                     * En plein, ils rendent 4,73 et 11,61.
                                     *
                                     * Le record portait `yellow-500`, qui est la
                                     * valeur de l'alerte : un trophee n'est pas
                                     * un avertissement, et le jour ou l'alerte
                                     * serait retouchee le badge aurait suivi.
                                     */
                                    notification.data.type === 'personal_record'
                                        ? 'bg-accent-secondary text-text-on-accent'
                                        : 'bg-accent-info text-text-on-accent',
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
                                <h4 class="text-text-main font-semibold">{{ notification.data.title }}</h4>
                                <p class="text-text-muted text-sm">{{ notification.data.message }}</p>
                                <span class="text-text-muted/30 mt-2 block text-[10px] tracking-wider uppercase">
                                    {{ formatDate(notification.created_at) }}
                                </span>
                            </div>
                        </div>

                        <GlassIconButton
                            v-if="!notification.read_at"
                            icon="check"
                            label="Marquer comme lu"
                            compact
                            title="Marquer comme lu"
                            @click="markAsRead(notification.id)"
                        />
                    </div>
                </GlassCard>
            </div>

            <nav
                v-if="notifications.last_page > 1"
                class="mt-6 flex items-center justify-center gap-4"
                aria-label="Pagination des notifications"
            >
                <GlassButton
                    :disabled="!notifications.prev_page_url"
                    @click="goToPage(notifications.prev_page_url)"
                    aria-label="Page précédente"
                    dusk="notifications-prev"
                >
                    <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
                </GlassButton>

                <span class="text-text-muted text-sm font-bold" aria-live="polite">
                    Page {{ notifications.current_page }} sur {{ notifications.last_page }}
                </span>

                <GlassButton
                    :disabled="!notifications.next_page_url"
                    @click="goToPage(notifications.next_page_url)"
                    aria-label="Page suivante"
                    dusk="notifications-next"
                >
                    <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
                </GlassButton>
            </nav>
        </div>
    </AuthenticatedLayout>
</template>

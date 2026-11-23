<script setup>
import { Link } from '@inertiajs/vue3'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
</script>

<template>
    <Link
        v-press
        :href="route('notifications.index')"
        class="verre-regulier text-text-muted rounded-capsule relative flex size-11 shrink-0 items-center justify-center transition"
        :aria-label="
            $page.props.auth?.user?.unread_notifications_count > 0
                ? `Notifications (${$page.props.auth?.user?.unread_notifications_count} non lues)`
                : 'Notifications'
        "
    >
        <GlassIcon name="notifications" />
        <span
            v-if="$page.props.auth?.user?.unread_notifications_count > 0"
            class="accent-fill text-2xs absolute -top-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full font-semibold"
        >
            {{ $page.props.auth?.user?.unread_notifications_count }}
        </span>
    </Link>
</template>

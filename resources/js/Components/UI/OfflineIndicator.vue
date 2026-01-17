<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { syncService } from '@/Services/SyncService'

const isOnline = ref(navigator.onLine)
const isSyncing = ref(false)

const updateStatus = () => {
    isOnline.value = navigator.onLine
}

onMounted(() => {
    window.addEventListener('online', updateStatus)
    window.addEventListener('offline', updateStatus)

    // Check sync status every second (could be improved with events)
    const interval = setInterval(() => {
        isSyncing.value = syncService.isSyncing
    }, 1000)

    onUnmounted(() => {
        window.removeEventListener('online', updateStatus)
        window.removeEventListener('offline', updateStatus)
        clearInterval(interval)
    })
})
</script>

<template>
    <div
        v-if="!isOnline || isSyncing"
        class="pointer-events-none fixed left-0 right-0 top-0 z-[100] flex justify-center"
    >
        <div
            class="mt-2 transform rounded-full px-4 py-1.5 text-xs font-black uppercase tracking-widest shadow-lg transition-all duration-500"
            :class="[
                !isOnline
                    ? 'translate-y-0 bg-slate-800 text-white opacity-100'
                    : isSyncing
                      ? 'translate-y-0 animate-pulse bg-electric-orange text-white opacity-100'
                      : '-translate-y-full opacity-0',
            ]"
        >
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-sm">
                    {{ !isOnline ? 'cloud_off' : 'sync' }}
                </span>
                {{ !isOnline ? 'Hors ligne' : 'Synchronisation...' }}
            </div>
        </div>
    </div>
</template>

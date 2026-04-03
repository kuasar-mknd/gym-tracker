<script setup>
defineProps({
    user: { type: Object, required: true },
})
</script>

<template>
    <header
        id="dashboard-header"
        class="animate-fade-in flex items-center justify-between rounded-3xl border border-white/20 bg-white/10 p-4 shadow-lg backdrop-blur-md transition-all duration-300 hover:bg-white/20 hover:shadow-xl active:scale-[0.98]"
    >
        <div class="flex items-center gap-4">
            <!-- Avatar with gradient border -->
            <div class="relative">
                <div
                    class="from-electric-orange to-vivid-violet relative size-14 overflow-hidden rounded-full bg-linear-to-tr p-[2px]"
                >
                    <div
                        class="flex h-full w-full items-center justify-center overflow-hidden rounded-full border-2 border-white bg-white dark:border-slate-700 dark:bg-slate-800"
                    >
                        <span class="text-gradient text-2xl font-black">
                            {{ user.name?.charAt(0).toUpperCase() }}
                        </span>
                    </div>
                </div>
            </div>
            <div>
                <p
                    class="text-text-muted mb-0.5 text-[10px] font-black tracking-[0.2em] uppercase"
                    dusk="dashboard-welcome"
                >
                    BON RETOUR
                </p>
                <h1
                    class="font-display text-text-main text-3xl font-black tracking-tighter uppercase italic dark:text-white"
                >
                    {{ user.name?.split(' ')[0] }}
                </h1>
            </div>
        </div>

        <!-- Streak Badge -->
        <div
            class="streak-badge group flex cursor-pointer items-center gap-1.5 rounded-2xl border border-white/20 bg-white/10 px-3 py-2 backdrop-blur-md transition-all duration-300 hover:-translate-y-1 hover:bg-white/20 hover:shadow-lg active:scale-95"
        >
            <span
                class="material-symbols-outlined text-electric-orange text-[24px] transition-transform duration-300 group-hover:scale-110 group-hover:animate-pulse"
                style="font-variation-settings: 'FILL' 1"
                >local_fire_department</span
            >
            <span class="text-text-main text-xl font-black italic dark:text-white">
                {{ user.current_streak || 0 }}
                <span class="text-text-muted ml-0.5 text-[10px] font-bold uppercase not-italic">Jours</span>
            </span>
        </div>
    </header>
</template>

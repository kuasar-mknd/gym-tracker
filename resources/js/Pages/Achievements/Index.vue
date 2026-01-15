<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import { Head } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

const props = defineProps({
    achievements: Array,
    summary: Object,
})

const currentCategory = ref('all')

const categories = [
    { value: 'all', label: 'Tous' },
    { value: 'consistency', label: 'Constance' },
    { value: 'strength', label: 'Force' },
    { value: 'volume', label: 'Volume' },
]

const filteredAchievements = computed(() => {
    if (currentCategory.value === 'all') {
        return props.achievements
    }
    return props.achievements.filter((a) => a.category === currentCategory.value)
})

const getCategoryLabel = (category) => {
    return categories.find((c) => c.value === category)?.label || category
}
</script>

<template>
    <Head title="Succès & Badges" />

    <AuthenticatedLayout page-title="Succès & Badges">
        <template #header>
            <div class="flex items-end justify-between">
                <div>
                    <h1 class="flex items-center gap-2 text-2xl font-bold text-white">Trophées 🏆</h1>
                    <p class="mt-1 text-white/60">Tes exploits et récompenses.</p>
                </div>
                <div class="text-right">
                    <div class="text-2xl font-bold text-accent-primary">
                        {{ summary.unlocked }} / {{ summary.total }}
                    </div>
                    <div class="text-xs uppercase tracking-wider text-white/40">Débloqués</div>
                </div>
            </div>
        </template>

        <div class="space-y-6 pb-24">
            <!-- Categories -->
            <div class="scrollbar-none flex animate-slide-up gap-2 overflow-x-auto pb-2" style="animation-delay: 0.1s">
                <button
                    v-for="cat in categories"
                    :key="cat.value"
                    @click="currentCategory = cat.value"
                    class="whitespace-nowrap rounded-full px-4 py-2 text-sm font-medium transition-all"
                    :class="
                        currentCategory === cat.value
                            ? 'bg-accent-primary text-white shadow-lg shadow-accent-primary/20'
                            : 'bg-white/5 text-white/60 hover:bg-white/10'
                    "
                >
                    {{ cat.label }}
                </button>
            </div>

            <!-- Achievements Grid -->
            <div class="grid animate-slide-up grid-cols-2 gap-4 sm:grid-cols-3" style="animation-delay: 0.2s">
                <div v-for="achievement in filteredAchievements" :key="achievement.id" class="group relative">
                    <GlassCard
                        padding="p-4"
                        class="flex h-full flex-col items-center text-center transition-all duration-300"
                        :class="[
                            achievement.is_unlocked
                                ? 'border-accent-primary/20 bg-glass-strong'
                                : 'opacity-60 grayscale',
                        ]"
                    >
                        <!-- Badge Icon -->
                        <div
                            class="mb-3 flex h-16 w-16 items-center justify-center rounded-2xl text-4xl transition-transform duration-300 group-hover:scale-110"
                            :class="achievement.is_unlocked ? 'bg-accent-primary/10' : 'bg-white/5'"
                        >
                            {{ achievement.icon }}
                        </div>

                        <!-- Name -->
                        <h3 class="mb-1 line-clamp-1 text-sm font-bold text-white">
                            {{ achievement.name }}
                        </h3>

                        <!-- Description -->
                        <p class="line-clamp-2 text-[10px] text-white/40">
                            {{ achievement.description }}
                        </p>

                        <!-- Tooltip Overlay -->
                        <div
                            class="pointer-events-none absolute inset-0 z-10 flex flex-col items-center justify-center rounded-[20px] bg-black/80 p-4 text-center opacity-0 transition-opacity group-hover:opacity-100"
                        >
                            <span class="mb-1 text-xs font-bold text-accent-primary">{{ achievement.name }}</span>
                            <span class="text-[10px] leading-tight text-white/80">{{ achievement.description }}</span>
                            <div v-if="achievement.is_unlocked" class="mt-2 text-[8px] italic text-white/30">
                                Débloqué le {{ new Date(achievement.unlocked_at).toLocaleDateString('fr-FR') }}
                            </div>
                        </div>

                        <!-- Unlocked Checkmark -->
                        <div
                            v-if="achievement.is_unlocked"
                            class="absolute right-2 top-2 flex h-5 w-5 items-center justify-center rounded-full bg-accent-success shadow-lg"
                        >
                            <svg class="h-3 w-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="3"
                                    d="M5 13l4 4L19 7"
                                />
                            </svg>
                        </div>
                    </GlassCard>
                </div>
            </div>

            <!-- Empty State -->
            <div v-if="filteredAchievements.length === 0" class="py-12 text-center text-white/40">
                Aucun badge dans cette catégorie.
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.scrollbar-none::-webkit-scrollbar {
    display: none;
}
.scrollbar-none {
    -ms-overflow-style: none;
    scrollbar-width: none;
}
</style>

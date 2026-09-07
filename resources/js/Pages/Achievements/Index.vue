<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import { Head } from '@inertiajs/vue3'
import { ref, computed } from 'vue'
import GlassChip from '@/Components/UI/GlassChip.vue'

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
</script>

<template>
    <Head title="Succès & Badges" />

    <AuthenticatedLayout page-title="Succès & Badges">
        <template #header>
            <div class="flex items-end justify-between">
                <div>
                    <h1 class="text-text-main flex items-center gap-2 text-2xl font-bold">Trophées 🏆</h1>
                    <p class="text-text-muted mt-1">Tes exploits et récompenses.</p>
                </div>
                <div class="text-right">
                    <div class="text-accent-primary-deep text-2xl font-bold">
                        {{ summary.unlocked }} / {{ summary.total }}
                    </div>
                    <div class="text-text-muted/50 text-xs tracking-wider uppercase">Débloqués</div>
                </div>
            </div>
        </template>

        <div class="space-y-6 pb-24">
            <!-- Categories -->
            <div class="stagger-2 animate-slide-up flex scrollbar-none gap-2 overflow-x-auto pb-2">
                <GlassChip
                    v-for="cat in categories"
                    :key="cat.value"
                    :active="currentCategory === cat.value"
                    @click="currentCategory = cat.value"
                >
                    {{ cat.label }}
                </GlassChip>
            </div>

            <!-- Achievements Grid -->
            <div class="stagger-4 animate-slide-up grid grid-cols-2 gap-4 sm:grid-cols-3">
                <div v-for="achievement in filteredAchievements" :key="achievement.id" class="group relative">
                    <GlassCard
                        padding="p-4"
                        class="flex h-full flex-col items-center text-center transition duration-300"
                        :class="[
                            achievement.is_unlocked
                                ? 'bg-surface-glass-strong border-accent-primary/20'
                                : 'opacity-60 grayscale',
                        ]"
                    >
                        <!-- Badge Icon -->
                        <div
                            class="mb-3 flex h-16 w-16 items-center justify-center rounded-2xl text-4xl transition-transform duration-300 group-hover:scale-110"
                            :class="achievement.is_unlocked ? 'bg-accent-primary/10' : 'bg-surface-card/5'"
                        >
                            {{ achievement.icon }}
                        </div>

                        <!-- Name -->
                        <h3 class="text-text-main mb-1 line-clamp-1 text-sm font-bold">
                            {{ achievement.name }}
                        </h3>

                        <!-- Description -->
                        <p class="text-text-muted text-2xs line-clamp-2">
                            {{ achievement.description }}
                        </p>

                        <!-- Tooltip Overlay -->
                        <div
                            class="bg-text-main/80 pointer-events-none absolute inset-0 z-10 flex flex-col items-center justify-center rounded-xl p-4 text-center opacity-0 transition-opacity group-hover:opacity-100"
                        >
                            <!--
                                Le nom s'ecrit en CLAIR : cette infobulle est un
                                voile sombre (`bg-text-main/80`), pas une carte.
                                `accent-primary-deep` y rendait 1,91:1 — il est
                                fait pour ecrire sur les surfaces claires, et le
                                garde qui verifie ca ne peut pas connaitre un
                                fond pose par une classe voisine.
                            -->
                            <span class="text-text-on-dark-accent mb-1 text-xs font-bold">{{ achievement.name }}</span>
                            <span class="text-text-on-dark-accent/80 text-2xs leading-tight">{{
                                achievement.description
                            }}</span>
                            <div
                                v-if="achievement.is_unlocked"
                                class="text-text-on-dark-accent/30 text-2xs mt-2 italic"
                            >
                                Débloqué le {{ new Date(achievement.unlocked_at).toLocaleDateString('fr-FR') }}
                            </div>
                        </div>

                        <!-- Unlocked Checkmark -->
                        <div
                            v-if="achievement.is_unlocked"
                            class="bg-accent-state absolute top-2 right-2 flex h-5 w-5 items-center justify-center rounded-full shadow-lg"
                        >
                            <svg
                                class="text-text-on-accent h-3 w-3"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
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
            <div v-if="filteredAchievements.length === 0" class="text-text-muted py-12 text-center">
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

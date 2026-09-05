<script setup>
/**
 * La recherche et les pastilles de catégorie de la bibliothèque. Le composant
 * ne filtre rien : il rend les deux valeurs à la page par `v-model`, et tient
 * les deux raccourcis clavier, ⌘K pour venir au champ et Échap pour le vider.
 */
import { onMounted, onUnmounted, ref } from 'vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import { CATEGORY_COLORS, EXERCISE_CATEGORIES } from '@/Utils/constants'

defineProps({
    recherche: { type: String, default: '' },
    categorie: { type: String, default: 'all' },
})

const emit = defineEmits(['update:recherche', 'update:categorie'])

const searchInput = ref(null)

const handleKeyDown = (e) => {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault()
        searchInput.value?.focus()
    }

    // `searchInput` porte le proxy exposé par GlassInput, pas son élément
    // racine : le champ concerné se reconnaît par l'input que le composant expose.
    if (e.key === 'Escape' && document.activeElement === searchInput.value?.el) {
        searchInput.value.blur()
        emit('update:recherche', '')
    }
}

onMounted(() => {
    document.addEventListener('keydown', handleKeyDown)
})

onUnmounted(() => {
    document.removeEventListener('keydown', handleKeyDown)
})
</script>

<template>
    <div class="space-y-6">
        <div class="animate-slide-up" style="animation-delay: 0.1s">
            <GlassInput
                id="search-exercises-input"
                ref="searchInput"
                :model-value="recherche"
                @update:model-value="$emit('update:recherche', $event)"
                type="search"
                size="lg"
                label="Rechercher des exercices"
                hide-label
                dusk="search-exercises"
                placeholder="Recherche exercices..."
                :aria-label="'Rechercher des exercices (Raccourci : ⌘K)'"
            >
                <template #suffix>
                    <div
                        class="text-text-muted/40 border-border hidden items-center gap-1 rounded-lg border px-2 py-1 text-[10px] font-bold tracking-widest uppercase sm:flex"
                        aria-hidden="true"
                    >
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">keyboard</span>
                        ⌘K
                    </div>
                </template>
            </GlassInput>
        </div>

        <div class="hide-scrollbar animate-slide-up flex gap-2 overflow-x-auto pb-2" style="animation-delay: 0.15s">
            <button
                v-press="{ haptic: 'selection' }"
                @click="$emit('update:categorie', 'all')"
                dusk="category-pill-all"
                :class="[
                    'category-pill shrink-0 transition-all',
                    categorie === 'all'
                        ? 'bg-text-main text-surface-card shadow-lg'
                        : 'text-text-main border-border bg-surface-card border',
                ]"
                :aria-pressed="categorie === 'all'"
            >
                <span class="material-symbols-outlined text-lg" aria-hidden="true">apps</span>
                Tous
            </button>
            <button
                v-for="cat in EXERCISE_CATEGORIES"
                :key="cat"
                v-press="{ haptic: 'selection' }"
                @click="$emit('update:categorie', cat)"
                :dusk="`category-pill-${cat}`"
                :class="[
                    'category-pill shrink-0 transition-all',
                    categorie === cat
                        ? (CATEGORY_COLORS[cat] ?? 'category-fill-other')
                        : 'text-text-main border-border bg-surface-card border',
                ]"
                :aria-pressed="categorie === cat"
            >
                {{ cat }}
            </button>
        </div>
    </div>
</template>

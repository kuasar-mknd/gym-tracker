<script setup>
/**
 * La recherche et les pastilles de catégorie de la bibliothèque. Le composant
 * ne filtre rien : il rend les deux valeurs à la page par `v-model`, et tient
 * les deux raccourcis clavier, ⌘K pour venir au champ et Échap pour le vider.
 */
import { onMounted, onUnmounted, ref } from 'vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { CATEGORY_COLORS, EXERCISE_CATEGORIES } from '@/Utils/constants'
import GlassChip from '@/Components/UI/GlassChip.vue'

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
        <div class="stagger-2 animate-slide-up">
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
                        class="text-text-muted/40 border-border text-2xs hidden items-center gap-1 rounded-lg border px-2 py-1 font-bold tracking-widest uppercase sm:flex"
                        aria-hidden="true"
                    >
                        <GlassIcon name="keyboard" size="xs" />
                        ⌘K
                    </div>
                </template>
            </GlassInput>
        </div>

        <div class="stagger-3 hide-scrollbar animate-slide-up flex gap-2 overflow-x-auto pb-2">
            <GlassChip
                v-press="{ haptic: 'selection' }"
                dusk="category-pill-all"
                icon="apps"
                :active="categorie === 'all'"
                @click="$emit('update:categorie', 'all')"
            >
                Tous
            </GlassChip>
            <GlassChip
                v-for="cat in EXERCISE_CATEGORIES"
                :key="cat"
                v-press="{ haptic: 'selection' }"
                :dusk="`category-pill-${cat}`"
                :active="categorie === cat"
                :classe-active="`${CATEGORY_COLORS[cat] ?? 'category-fill-other'} shadow-cast`"
                @click="$emit('update:categorie', cat)"
            >
                {{ cat }}
            </GlassChip>
        </div>
    </div>
</template>

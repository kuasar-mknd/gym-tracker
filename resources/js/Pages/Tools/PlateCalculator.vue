<template>
    <Head title="Calculateur de Plaques" />

    <AuthenticatedLayout page-title="Plaques" show-back back-route="profile.index">
        <div class="space-y-6">
            <!-- Header -->
            <header class="animate-fade-in">
                <h1
                    class="font-display text-4xl font-black uppercase italic leading-none tracking-tighter text-text-main"
                >
                    Calculateur<br />
                    <span class="text-gradient">de Plaques</span>
                </h1>
                <p class="mt-2 text-sm font-semibold uppercase tracking-wider text-text-muted">
                    Charge ta barre parfaitement
                </p>
            </header>

            <!-- Calculator Section -->
            <GlassCard class="animate-slide-up" style="animation-delay: 0.05s">
                <div class="space-y-6">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="font-display-label mb-2 block text-text-muted">Poids Cible</label>
                            <div class="relative">
                                <input
                                    type="number"
                                    v-model="targetWeight"
                                    placeholder="100"
                                    step="0.5"
                                    class="h-16 w-full rounded-2xl border-2 border-slate-200 bg-white px-4 text-center font-display text-3xl font-black text-text-main outline-none transition-all focus:border-electric-orange focus:ring-2 focus:ring-electric-orange/20"
                                />
                                <span class="absolute right-4 top-1/2 -translate-y-1/2 font-bold text-text-muted"
                                    >kg</span
                                >
                            </div>
                        </div>
                        <div>
                            <label class="font-display-label mb-2 block text-text-muted">Poids Barre</label>
                            <div class="relative">
                                <input
                                    type="number"
                                    v-model="barWeight"
                                    placeholder="20"
                                    class="h-16 w-full rounded-2xl border-2 border-slate-200 bg-white px-4 text-center font-display text-3xl font-black text-text-main outline-none transition-all focus:border-electric-orange focus:ring-2 focus:ring-electric-orange/20"
                                />
                                <span class="absolute right-4 top-1/2 -translate-y-1/2 font-bold text-text-muted"
                                    >kg</span
                                >
                            </div>
                        </div>
                    </div>

                    <!-- Barbell Visualization -->
                    <div
                        v-if="calculatedPlates.length > 0"
                        class="mt-8 overflow-x-auto rounded-3xl border border-slate-100 bg-slate-50 p-6"
                    >
                        <div class="relative flex h-[200px] min-w-[300px] items-center justify-center">
                            <!-- Bar -->
                            <div
                                class="absolute z-0 h-5 w-full rounded-full bg-gradient-to-r from-slate-300 via-slate-400 to-slate-300 shadow-inner"
                            ></div>

                            <!-- Center Marker -->
                            <div class="absolute z-10 h-24 w-2 rounded-full bg-slate-500"></div>

                            <!-- Left Side Plates -->
                            <div
                                class="absolute left-[50%] flex -translate-x-[calc(100%+14px)] flex-row-reverse items-center gap-1"
                            >
                                <div
                                    v-for="(plate, index) in calculatedPlates"
                                    :key="`left-${index}`"
                                    class="flex items-center justify-center rounded-md border-2 border-white/30 text-xs font-black shadow-lg"
                                    :class="[
                                        getPlateColor(plate.weight),
                                        parseFloat(plate.weight) === 15 || parseFloat(plate.weight) < 5
                                            ? 'text-text-main'
                                            : 'text-white',
                                    ]"
                                    :style="{
                                        height: `${getPlateSize(plate.weight)}px`,
                                        width: '24px',
                                    }"
                                >
                                    <span class="-rotate-90 whitespace-nowrap">{{ plate.weight }}</span>
                                </div>
                            </div>

                            <!-- Right Side Plates -->
                            <div class="absolute left-[50%] flex translate-x-[14px] items-center gap-1">
                                <div
                                    v-for="(plate, index) in calculatedPlates"
                                    :key="`right-${index}`"
                                    class="flex items-center justify-center rounded-md border-2 border-white/30 text-xs font-black shadow-lg"
                                    :class="[
                                        getPlateColor(plate.weight),
                                        parseFloat(plate.weight) === 15 || parseFloat(plate.weight) < 5
                                            ? 'text-text-main'
                                            : 'text-white',
                                    ]"
                                    :style="{
                                        height: `${getPlateSize(plate.weight)}px`,
                                        width: '24px',
                                    }"
                                >
                                    <span class="rotate-90 whitespace-nowrap">{{ plate.weight }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Weight Info -->
                        <div class="mt-6 text-center">
                            <p class="text-lg font-bold text-text-main">
                                Poids Total:
                                <span class="font-display text-2xl font-black text-electric-orange"
                                    >{{ actualWeight }} kg</span
                                >
                            </p>
                            <p class="mt-2 text-sm text-text-muted">
                                Plaques par côté:
                                <span class="font-bold">{{
                                    calculatedPlates.map((p) => p.weight + 'kg').join(' + ')
                                }}</span>
                            </p>
                        </div>
                    </div>

                    <!-- Cannot load message -->
                    <div
                        v-else-if="targetWeight > barWeight"
                        class="mt-8 rounded-3xl border border-slate-100 bg-slate-50 py-8 text-center"
                    >
                        <span class="material-symbols-outlined mb-3 text-5xl text-slate-300">error</span>
                        <p class="font-medium text-text-muted">
                            Impossible de charger ce poids avec les plaques disponibles.
                        </p>
                    </div>
                </div>
            </GlassCard>

            <!-- Inventory Section -->
            <GlassCard class="animate-slide-up" style="animation-delay: 0.1s">
                <div class="space-y-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="font-display text-lg font-black uppercase italic text-text-main">
                                Mon Inventaire
                            </h2>
                            <p class="mt-1 text-xs font-bold uppercase tracking-wider text-text-muted">
                                Plaques disponibles
                            </p>
                        </div>
                        <GlassButton @click="addingPlate = true" variant="primary" size="sm" icon="add">
                            Ajouter
                        </GlassButton>
                    </div>

                    <div v-if="plates.length === 0" class="py-12 text-center">
                        <span class="material-symbols-outlined mb-3 text-6xl text-slate-200">inventory_2</span>
                        <p class="font-medium text-text-muted">Aucune plaque dans l'inventaire.</p>
                        <p class="mt-1 text-sm text-text-muted/70">Ajoute tes plaques pour commencer.</p>
                    </div>

                    <div v-else class="grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                        <div v-for="plate in plates" :key="plate.id" class="group relative">
                            <div
                                class="rounded-2xl border-2 p-4 text-center transition-all hover:shadow-md"
                                :class="[
                                    getPlateColor(parseFloat(plate.weight)),
                                    parseFloat(plate.weight) >= 5 && parseFloat(plate.weight) < 10
                                        ? 'border-slate-200'
                                        : 'border-transparent',
                                ]"
                            >
                                <div
                                    class="font-display text-3xl font-black"
                                    :class="
                                        parseFloat(plate.weight) >= 5 && parseFloat(plate.weight) < 10
                                            ? 'text-text-main'
                                            : 'text-white'
                                    "
                                >
                                    {{ plate.weight }}
                                </div>
                                <div
                                    class="mt-1 text-xs font-bold uppercase tracking-wider"
                                    :class="
                                        parseFloat(plate.weight) >= 5 && parseFloat(plate.weight) < 10
                                            ? 'text-text-muted'
                                            : 'text-white/70'
                                    "
                                >
                                    x {{ plate.quantity }}
                                </div>
                                <button
                                    @click="deletePlate(plate)"
                                    class="absolute -right-2 -top-2 flex size-6 items-center justify-center rounded-full bg-red-500 text-white opacity-0 shadow-md transition-all hover:bg-red-600 group-hover:opacity-100"
                                >
                                    <span class="material-symbols-outlined text-sm">close</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </GlassCard>
        </div>

        <!-- Add Plate Modal -->
        <Modal :show="addingPlate" @close="addingPlate = false">
            <div class="space-y-6 p-6">
                <h2 class="font-display text-xl font-black uppercase italic text-text-main">Ajouter une plaque</h2>

                <div class="space-y-4">
                    <div>
                        <label class="font-display-label mb-2 block text-text-muted">Poids (kg)</label>
                        <GlassInput type="number" v-model="newPlate.weight" placeholder="ex: 20" step="0.5" />
                    </div>
                    <div>
                        <label class="font-display-label mb-2 block text-text-muted">Quantité (total)</label>
                        <GlassInput type="number" v-model="newPlate.quantity" placeholder="ex: 4" />
                        <p class="mt-2 text-xs text-text-muted">Nombre total de plaques disponibles (pas de paires)</p>
                    </div>
                </div>

                <div class="flex justify-end gap-3 border-t border-slate-100 pt-4">
                    <GlassButton @click="addingPlate = false" variant="ghost">Annuler</GlassButton>
                    <GlassButton @click="savePlate" variant="primary" :disabled="form.processing"
                        >Enregistrer</GlassButton
                    >
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Head, useForm, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import Modal from '@/Components/Modal.vue'

const props = defineProps({
    plates: {
        type: Array,
        required: true,
    },
})

const targetWeight = ref(100)
const barWeight = ref(20)
const addingPlate = ref(false)

const newPlate = useForm({
    weight: '',
    quantity: '',
})

const form = useForm({})

const savePlate = () => {
    newPlate.post(route('plates.store'), {
        onSuccess: () => {
            addingPlate.value = false
            newPlate.reset()
        },
    })
}

const deletePlate = (plate) => {
    if (confirm('Supprimer cette plaque ?')) {
        router.delete(route('plates.destroy', { plate: plate }))
    }
}

const calculatedPlates = computed(() => {
    if (!targetWeight.value || !barWeight.value || targetWeight.value <= barWeight.value) {
        return []
    }

    let remainingWeight = (targetWeight.value - barWeight.value) / 2
    const result = []

    const inventory = props.plates
        .map((p) => ({ ...p, weight: parseFloat(p.weight) }))
        .sort((a, b) => b.weight - a.weight)

    for (const plate of inventory) {
        const availablePairs = Math.floor(plate.quantity / 2)
        let pairsToUse = 0

        while (remainingWeight >= plate.weight && pairsToUse < availablePairs) {
            remainingWeight -= plate.weight
            pairsToUse++
            result.push({ weight: plate.weight })
        }

        if (remainingWeight < 0.01) remainingWeight = 0
    }

    return result
})

const actualWeight = computed(() => {
    const platesWeight = calculatedPlates.value.reduce((sum, p) => sum + p.weight, 0) * 2
    return barWeight.value + platesWeight
})

const getPlateSize = (weight) => {
    const max = 180
    const min = 60
    const maxWeight = 25
    return Math.max(min, Math.min(max, (weight / maxWeight) * max))
}

const getPlateColor = (weight) => {
    // Standard Olympic plate colors
    if (weight >= 25) return 'bg-red-600'
    if (weight >= 20) return 'bg-blue-600'
    if (weight >= 15) return 'bg-yellow-500'
    if (weight >= 10) return 'bg-green-600'
    if (weight >= 5) return 'bg-white'
    if (weight >= 2.5) return 'bg-slate-800'
    return 'bg-slate-400'
}
</script>

<template>
    <Head title="Calculateur de Plaques" />

    <AuthenticatedLayout page-title="Plaques" show-back back-route="profile.index">
        <div class="space-y-6">
            <!-- Header -->
            <header class="animate-fade-in">
                <h1
                    class="font-display text-text-main text-4xl leading-none font-black tracking-tighter uppercase italic"
                >
                    Calculateur<br />
                    <span class="text-gradient">de Plaques</span>
                </h1>
                <p class="text-text-muted mt-2 text-sm font-semibold tracking-wider uppercase">
                    Charge ta barre parfaitement
                </p>
            </header>

            <!-- Calculator Section -->
            <GlassCard class="animate-slide-up" style="animation-delay: 0.05s">
                <div class="space-y-6">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="font-display-label text-text-muted mb-2 block">Poids Cible</label>
                            <div class="relative">
                                <input
                                    type="number"
                                    v-model="targetWeight"
                                    placeholder="100"
                                    step="0.5"
                                    class="font-display text-text-main focus:border-electric-orange focus:ring-electric-orange/20 h-16 w-full rounded-2xl border-2 border-slate-200 bg-white px-4 text-center text-3xl font-black transition-all outline-none focus:ring-2"
                                />
                                <span class="text-text-muted absolute top-1/2 right-4 -translate-y-1/2 font-bold"
                                    >kg</span
                                >
                            </div>
                        </div>
                        <div>
                            <label class="font-display-label text-text-muted mb-2 block">Poids Barre</label>
                            <div class="relative">
                                <input
                                    type="number"
                                    v-model="barWeight"
                                    placeholder="20"
                                    class="font-display text-text-main focus:border-electric-orange focus:ring-electric-orange/20 h-16 w-full rounded-2xl border-2 border-slate-200 bg-white px-4 text-center text-3xl font-black transition-all outline-none focus:ring-2"
                                />
                                <span class="text-text-muted absolute top-1/2 right-4 -translate-y-1/2 font-bold"
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
                                class="absolute z-0 h-5 w-full rounded-full bg-linear-to-r from-slate-300 via-slate-400 to-slate-300 shadow-inner"
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
                            <p class="text-text-main text-lg font-bold">
                                Poids Total:
                                <span class="font-display text-electric-orange text-2xl font-black"
                                    >{{ actualWeight }} kg</span
                                >
                            </p>
                            <p class="text-text-muted mt-2 text-sm">
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
                        <p class="text-text-muted font-medium">
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
                            <h2 class="font-display text-text-main text-lg font-black uppercase italic">
                                Mon Inventaire
                            </h2>
                            <p class="text-text-muted mt-1 text-xs font-bold tracking-wider uppercase">
                                Plaques disponibles
                            </p>
                        </div>
                        <GlassButton @click="addingPlate = true" variant="primary" size="sm" icon="add">
                            Ajouter
                        </GlassButton>
                    </div>

                    <div v-if="plates.length === 0" class="py-12 text-center">
                        <span class="material-symbols-outlined mb-3 text-6xl text-slate-200">inventory_2</span>
                        <p class="text-text-muted font-medium">Aucune plaque dans l'inventaire.</p>
                        <p class="text-text-muted/70 mt-1 text-sm">Ajoute tes plaques pour commencer.</p>
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
                                    class="mt-1 text-xs font-bold tracking-wider uppercase"
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
                                    class="absolute -top-2 -right-2 flex size-6 items-center justify-center rounded-full bg-red-500 text-white opacity-0 shadow-md transition-all group-hover:opacity-100 hover:bg-red-600"
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
                <h2 class="font-display text-text-main text-xl font-black uppercase italic">Ajouter une plaque</h2>

                <div class="space-y-4">
                    <div>
                        <label class="font-display-label text-text-muted mb-2 block">Poids (kg)</label>
                        <GlassInput type="number" v-model="newPlate.weight" placeholder="ex: 20" step="0.5" />
                    </div>
                    <div>
                        <label class="font-display-label text-text-muted mb-2 block">Quantité (total)</label>
                        <GlassInput type="number" v-model="newPlate.quantity" placeholder="ex: 4" />
                        <p class="text-text-muted mt-2 text-xs">Nombre total de plaques disponibles (pas de paires)</p>
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

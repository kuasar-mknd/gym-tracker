<template>
    <AuthenticatedLayout>
        <template #header>
            <PageHeader title="Plate Calculator" />
        </template>

        <div class="space-y-6">
            <!-- Calculator Section -->
            <GlassCard>
                <div class="space-y-6 p-6">
                    <h2 class="text-xl font-bold text-white">Calculator</h2>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div class="space-y-2">
                            <InputLabel value="Target Weight" />
                            <GlassInput type="number" v-model="targetWeight" placeholder="e.g. 100" step="0.5" />
                        </div>
                        <div class="space-y-2">
                            <InputLabel value="Bar Weight" />
                            <GlassInput type="number" v-model="barWeight" placeholder="e.g. 20" />
                        </div>
                    </div>

                    <!-- Visualization -->
                    <div v-if="calculatedPlates.length > 0" class="mt-8 overflow-x-auto rounded-xl bg-white/5 p-4">
                        <div class="relative flex h-[200px] min-w-[300px] items-center justify-center">
                            <!-- Bar -->
                            <div class="absolute z-0 h-4 w-full rounded-full bg-gray-400"></div>

                            <!-- Center Marker -->
                            <div class="absolute z-0 h-20 w-1 bg-gray-500"></div>

                            <!-- Left Side Plates -->
                            <div
                                class="absolute left-[50%] flex -translate-x-[calc(100%+10px)] flex-row-reverse items-center gap-1"
                            >
                                <div
                                    v-for="(plate, index) in calculatedPlates"
                                    :key="`left-${index}`"
                                    class="flex items-center justify-center border-2 border-white/20 bg-red-500 text-xs font-bold text-white shadow-lg"
                                    :class="getPlateColor(plate.weight)"
                                    :style="{
                                        height: `${getPlateSize(plate.weight)}px`,
                                        width: '20px',
                                        borderRadius: '4px',
                                    }"
                                >
                                    <span class="-rotate-90 whitespace-nowrap">{{ plate.weight }}</span>
                                </div>
                            </div>

                            <!-- Right Side Plates -->
                            <div class="absolute left-[50%] flex translate-x-[10px] items-center gap-1">
                                <div
                                    v-for="(plate, index) in calculatedPlates"
                                    :key="`right-${index}`"
                                    class="flex items-center justify-center border-2 border-white/20 bg-red-500 text-xs font-bold text-white shadow-lg"
                                    :class="getPlateColor(plate.weight)"
                                    :style="{
                                        height: `${getPlateSize(plate.weight)}px`,
                                        width: '20px',
                                        borderRadius: '4px',
                                    }"
                                >
                                    <span class="rotate-90 whitespace-nowrap">{{ plate.weight }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 text-center text-white/80">
                            <p>
                                Total Weight: <span class="font-bold text-white">{{ actualWeight }}</span>
                            </p>
                            <p class="text-sm">
                                Plates per side: {{ calculatedPlates.map((p) => p.weight).join(', ') }}
                            </p>
                        </div>
                    </div>
                    <div v-else-if="targetWeight > 0" class="mt-8 text-center text-white/50">
                        Cannot load exact weight with available plates.
                    </div>
                </div>
            </GlassCard>

            <!-- Inventory Section -->
            <GlassCard>
                <div class="space-y-6 p-6">
                    <div class="flex items-center justify-between">
                        <h2 class="text-xl font-bold text-white">Plate Inventory</h2>
                        <GlassButton @click="addingPlate = true" size="sm"> Add Plate </GlassButton>
                    </div>

                    <div v-if="plates.length === 0" class="py-8 text-center text-white/50">
                        No plates in inventory. Add some to start calculating.
                    </div>

                    <div v-else class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
                        <div v-for="plate in plates" :key="plate.id" class="group relative">
                            <div
                                class="rounded-xl border border-white/10 bg-white/5 p-4 transition-colors hover:bg-white/10"
                            >
                                <div class="text-center">
                                    <div class="text-2xl font-bold text-white">{{ plate.weight }}</div>
                                    <div class="text-sm text-white/60">x {{ plate.quantity }}</div>
                                </div>
                                <button
                                    @click="deletePlate(plate)"
                                    class="absolute right-2 top-2 text-red-400 opacity-0 transition-opacity group-hover:opacity-100"
                                >
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="h-4 w-4"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M6 18L18 6M6 6l12 12"
                                        />
                                    </svg>
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
                <h2 class="text-lg font-medium text-white">Add New Plate</h2>

                <div class="space-y-4">
                    <div>
                        <InputLabel value="Weight" />
                        <GlassInput
                            type="number"
                            v-model="newPlate.weight"
                            placeholder="e.g. 20"
                            step="0.5"
                            class="w-full"
                        />
                    </div>
                    <div>
                        <InputLabel value="Quantity (Total)" />
                        <GlassInput type="number" v-model="newPlate.quantity" placeholder="e.g. 4" class="w-full" />
                        <p class="mt-1 text-sm text-white/50">Total number of plates available (not pairs)</p>
                    </div>
                </div>

                <div class="flex justify-end gap-4">
                    <SecondaryButton @click="addingPlate = false">Cancel</SecondaryButton>
                    <PrimaryButton @click="savePlate" :disabled="form.processing">Save</PrimaryButton>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import PageHeader from '@/Components/Navigation/PageHeader.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import InputLabel from '@/Components/InputLabel.vue'
import Modal from '@/Components/Modal.vue'
import SecondaryButton from '@/Components/SecondaryButton.vue'
import PrimaryButton from '@/Components/PrimaryButton.vue'

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
    if (confirm('Are you sure you want to remove this plate?')) {
        router.delete(route('plates.destroy', plate))
    }
}

const calculatedPlates = computed(() => {
    if (!targetWeight.value || !barWeight.value || targetWeight.value <= barWeight.value) {
        return []
    }

    let remainingWeight = (targetWeight.value - barWeight.value) / 2
    const result = []

    // Sort available plates by weight descending
    // Create a working copy of inventory
    const inventory = props.plates
        .map((p) => ({ ...p, weight: parseFloat(p.weight) }))
        .sort((a, b) => b.weight - a.weight)

    // Track used count per plate ID (or weight group)
    const usedCounts = {}

    for (const plate of inventory) {
        // Calculate max pairs we can use of this plate
        const availablePairs = Math.floor(plate.quantity / 2)

        let pairsToUse = 0

        while (remainingWeight >= plate.weight && pairsToUse < availablePairs) {
            remainingWeight -= plate.weight
            pairsToUse++
            result.push({ weight: plate.weight })
        }

        // Handle floating point precision issues roughly
        if (remainingWeight < 0.01) remainingWeight = 0
    }

    return result
})

const actualWeight = computed(() => {
    const platesWeight = calculatedPlates.value.reduce((sum, p) => sum + p.weight, 0) * 2
    return barWeight.value + platesWeight
})

const getPlateSize = (weight) => {
    // Map weight to height pixels roughly
    const max = 180 // max height px
    const min = 60 // min height px
    const maxWeight = 25 // assumption for max common plate

    return Math.max(min, Math.min(max, (weight / maxWeight) * max))
}

const getPlateColor = (weight) => {
    // Standard Olympic colors
    if (weight >= 25) return 'bg-red-600'
    if (weight >= 20) return 'bg-blue-600'
    if (weight >= 15) return 'bg-yellow-500'
    if (weight >= 10) return 'bg-green-600'
    if (weight >= 5) return 'bg-white text-black'
    if (weight >= 2.5) return 'bg-black'
    return 'bg-gray-400'
}
</script>

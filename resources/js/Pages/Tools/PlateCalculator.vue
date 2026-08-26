<template>
    <Head title="Calculateur de Plaques" />

    <AuthenticatedLayout page-title="Calculateur de Plaques" show-back back-route="tools.index">
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
                        <GlassBigNumber
                            id="plate-target-weight"
                            v-model="targetWeight"
                            label="Poids Cible"
                            unite="kg"
                            placeholder="100"
                            step="0.5"
                        />
                        <GlassBigNumber
                            id="plate-bar-weight"
                            v-model="barWeight"
                            label="Poids Barre"
                            unite="kg"
                            placeholder="20"
                        />
                    </div>

                    <!-- Barbell Visualization -->
                    <div
                        v-if="calculatedPlates.length > 0"
                        class="border-border bg-surface-sunken mt-8 overflow-x-auto rounded-3xl border p-6"
                    >
                        <div class="relative flex h-[200px] min-w-[300px] items-center justify-center">
                            <!-- Bar -->
                            <div
                                class="from-border-strong via-text-muted to-border-strong absolute z-0 h-5 w-full rounded-full bg-linear-to-r shadow-inner"
                            ></div>

                            <!-- Center Marker -->
                            <div class="bg-text-muted absolute z-10 h-24 w-2 rounded-full"></div>

                            <!-- Left Side Plates -->
                            <div
                                class="absolute left-[50%] flex -translate-x-[calc(100%+14px)] flex-row-reverse items-center gap-1"
                            >
                                <div
                                    v-for="(plate, index) in calculatedPlates"
                                    :key="`left-${index}`"
                                    class="border-surface-card/30 flex items-center justify-center rounded-md border-2 text-xs font-black shadow-lg"
                                    :class="getPlateColor(plate.weight)"
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
                                    class="border-surface-card/30 flex items-center justify-center rounded-md border-2 text-xs font-black shadow-lg"
                                    :class="getPlateColor(plate.weight)"
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
                                <span class="font-display text-accent-primary-deep text-2xl font-black"
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
                        class="border-border bg-surface-sunken mt-8 rounded-3xl border py-8 text-center"
                    >
                        <span class="material-symbols-outlined text-text-muted mb-3 text-5xl" aria-hidden="true"
                            >error</span
                        >
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
                        <span class="material-symbols-outlined text-surface-sunken mb-3 text-6xl" aria-hidden="true"
                            >inventory_2</span
                        >
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
                                        ? 'border-border'
                                        : 'border-transparent',
                                ]"
                            >
                                <!--
                                    Aucune couleur de texte ici : `plate-fill-*`
                                    la pose deja, et la calcule. Un ternaire
                                    separe la recollait par-dessus — du blanc sur
                                    tout ce qui n'est pas entre 5 et 10 kg, donc
                                    du blanc sur le disque JAUNE de 15 kg, a
                                    1,65:1, quand l'encre y rend 10,85:1.

                                    Le meme defaut avait ete corrige sur la barre
                                    et manque ici : deux endroits decidaient de
                                    la meme chose, et un seul a ete repris.
                                -->
                                <div class="font-display text-3xl font-black">
                                    {{ plate.weight }}
                                </div>
                                <div class="mt-1 text-xs font-bold tracking-wider uppercase opacity-70">
                                    x {{ plate.quantity }}
                                </div>
                                <!--
                                    `compact` : la vignette reste à 24 px pour ne
                                    pas manger le disque qu'elle surplombe, mais
                                    la cible tactile fait bien 44 px.
                                -->
                                <GlassIconButton
                                    icon="close"
                                    label="Supprimer la plaque"
                                    ton="danger"
                                    title="Supprimer la plaque"
                                    compact
                                    class="bg-surface-card absolute -top-2 -right-2 rounded-full opacity-100 shadow-md sm:opacity-0 sm:group-hover:opacity-100 sm:focus-visible:opacity-100"
                                    @click="demanderSuppression(plate)"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </GlassCard>
        </div>

        <!-- Add Plate Modal -->
        <Modal :show="addingPlate" @close="addingPlate = false" aria-labelledby="add-plate-title">
            <div class="space-y-6 p-6">
                <h2 id="add-plate-title" class="font-display text-text-main text-xl font-black uppercase italic">
                    Ajouter une plaque
                </h2>

                <div class="space-y-4">
                    <!-- GlassInput renders this exact label markup itself, and wires
                         its `for` to the input it generates. -->
                    <div>
                        <GlassInput
                            type="number"
                            label="Poids (kg)"
                            v-model="newPlate.weight"
                            placeholder="ex: 20"
                            step="0.5"
                            :error="newPlate.errors.weight"
                        />
                    </div>
                    <div>
                        <GlassInput
                            type="number"
                            label="Quantité (total)"
                            v-model="newPlate.quantity"
                            placeholder="ex: 4"
                            :error="newPlate.errors.quantity"
                        />
                        <p class="text-text-muted mt-2 text-xs">Nombre total de plaques disponibles (pas de paires)</p>
                    </div>
                </div>

                <div class="border-border flex justify-end gap-3 border-t pt-4">
                    <GlassButton @click="addingPlate = false" variant="secondary">Annuler</GlassButton>
                    <!-- Was :disabled="form.processing", where `form` was an empty
                         useForm({}) that never submits — so it read false forever
                         and the button stayed live through the request. -->
                    <GlassButton @click="savePlate" variant="primary" :loading="newPlate.processing"
                        >Enregistrer</GlassButton
                    >
                </div>
            </div>
        </Modal>
        <ConfirmDialog
            :ouvert="suppressionDemandee"
            titre="Retirer ce disque de ton inventaire ?"
            :description="disqueASupprimer?.weight ? `Le disque de ${disqueASupprimer.weight} kg sera retire.` : ''"
            @confirmer="confirmerSuppression"
            @annuler="annulerSuppression"
        />
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { calculatePlates, actualWeight as plateActualWeight } from '@/Utils/plates'
import { Head, useForm, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassBigNumber from '@/Components/UI/GlassBigNumber.vue'
import GlassIconButton from '@/Components/UI/GlassIconButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import Modal from '@/Components/UI/Modal.vue'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import { useConfirmation } from '@/composables/useConfirmation'

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

const savePlate = () => {
    newPlate.post(route('plates.store'), {
        onSuccess: () => {
            addingPlate.value = false
            newPlate.reset()
        },
    })
}

const {
    cible: disqueASupprimer,
    ouvert: suppressionDemandee,
    demander: demanderSuppression,
    annuler: annulerSuppression,
    confirmer: confirmerSuppression,
} = useConfirmation((plate, termine) => {
    router.delete(route('plates.destroy', { plate: plate.id ?? plate }), { onFinish: termine })
})

const calculatedPlates = computed(() => calculatePlates(targetWeight.value, barWeight.value, props.plates))

const actualWeight = computed(() => plateActualWeight(calculatedPlates.value, barWeight.value))

const getPlateSize = (weight) => {
    const max = 180
    const min = 60
    const maxWeight = 25
    return Math.max(min, Math.min(max, (weight / maxWeight) * max))
}

/**
 * La couleur normalisée d'un disque, selon son poids.
 *
 * Le code est olympique, pas décoratif : un disque de 25 kg est rouge partout
 * dans le monde. C'est pourquoi ces couleurs ont leur propre groupe dans la
 * charte et n'empruntent pas aux accents — les avoir écrites `bg-accent-danger`
 * et `bg-accent-warning` liait ce calculateur aux humeurs du thème, et
 * éclaircir l'alerte d'un cran aurait repeint une norme extérieure.
 *
 * @param {number} weight
 * @returns {string}
 */
const getPlateColor = (weight) => {
    if (weight >= 25) return 'plate-fill-25'
    if (weight >= 20) return 'plate-fill-20'
    if (weight >= 15) return 'plate-fill-15'
    if (weight >= 10) return 'plate-fill-10'
    if (weight >= 5) return 'plate-fill-5'
    if (weight >= 2.5) return 'plate-fill-2'
    return 'plate-fill-small'
}
</script>

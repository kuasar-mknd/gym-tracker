<script setup>
import { computed } from 'vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { Link } from '@inertiajs/vue3'
import { dateCourte, parseCalendarDate } from '@/Utils/date'
import { accord, nombre, pourcentage, progressionAffichee } from '@/Utils/nombre'

const props = defineProps({
    goal: {
        type: Object,
        required: true,
    },
})

const emit = defineEmits(['delete'])

const progress = computed(() => props.goal.progress_pct || 0)
const isCompleted = computed(() => !!props.goal.completed_at)

const pourcentageAffiche = computed(() => progressionAffichee(props.goal))

/**
 * Un objectif « par semaine » repart de zéro chaque lundi : sa cible se lit
 * « 3 séances par semaine », et son badge dit la semaine, pas la fin.
 */
const parSemaine = computed(() => props.goal.type === 'frequency' && props.goal.period === 'week')

const typeIcon = computed(() => {
    switch (props.goal.type) {
        case 'weight':
            return 'fitness_center'
        case 'frequency':
            return 'calendar_month'
        case 'volume':
            return 'bar_chart'
        case 'measurement':
            return 'straighten'
        default:
            return 'flag'
    }
})

const typeLabel = computed(() => {
    switch (props.goal.type) {
        case 'weight':
            return 'Poids (Max)'
        case 'frequency':
            return parSemaine.value ? 'Fréquence · semaine' : 'Fréquence'
        case 'volume':
            return 'Volume'
        case 'measurement':
            return 'Mesure'
        default:
            return 'Objectif'
    }
})

/**
 * L'unité qui suit une valeur de l'objectif. Celle d'un objectif de fréquence
 * est un nom, qui s'accorde : « 1 séance », « 5 séances » (#1980).
 */
const uniteDe = (valeur) => (props.goal.type === 'frequency' ? accord(valeur, 'séance', 'séances') : props.goal.unit)
const uniteDeLaCible = (valeur) => uniteDe(valeur) + (parSemaine.value ? ' par semaine' : '')

const formattedDeadline = computed(() => dateCourte(parseCalendarDate(props.goal.deadline)))

const statusColor = computed(() => {
    if (isCompleted.value) return 'text-accent-state-deep'
    if (progress.value > 25) return 'text-accent-primary-deep'
    return 'text-text-muted'
})
</script>

<template>
    <GlassCard v-press padding="p-5" class="relative overflow-hidden">
        <!-- Completion Badge -->
        <div
            v-if="isCompleted"
            class="bg-accent-state text-text-on-accent text-2xs absolute top-0 right-0 z-10 rounded-bl-xl px-3 py-1.5 font-semibold"
        >
            {{ parSemaine ? 'Semaine faite' : 'Complété' }}
        </div>

        <div class="relative z-10 mb-4 flex items-start justify-between">
            <div class="flex items-center gap-3">
                <div class="bg-surface-sunken text-text-muted rounded-icone flex h-12 w-12 items-center justify-center">
                    <GlassIcon :name="typeIcon" />
                </div>
                <div>
                    <h4 class="titre-plat line-clamp-1">
                        {{ goal.title }}
                    </h4>
                    <span class="etiquette">{{ typeLabel }}</span>
                </div>
            </div>

            <div class="shrink-0 text-right whitespace-nowrap">
                <div class="font-display text-lg font-semibold tabular-nums" :class="statusColor">
                    {{ pourcentage(pourcentageAffiche, 0) }}
                </div>
            </div>
        </div>

        <div class="relative z-10 space-y-4">
            <!-- Progress Bar Container -->
            <div class="space-y-1.5">
                <div class="etiquette flex justify-between">
                    <span>{{ nombre(goal.start_value) }} {{ uniteDe(goal.start_value) }}</span>
                    <span>{{ nombre(goal.target_value) }} {{ uniteDeLaCible(goal.target_value) }}</span>
                </div>
                <div
                    class="bg-surface-sunken h-2 w-full overflow-hidden rounded-full"
                    role="progressbar"
                    :aria-valuenow="progress"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div
                        class="h-full"
                        :class="
                            isCompleted
                                ? // Le vert acide ne se detache d'aucune piste claire : 1,13:1
                                  // contre la sienne. Le contour, lui, rend 4,02:1 — la
                                  // barre garde son neon et devient perceptible.
                                  'bg-accent-state ring-accent-state-deep ring-1 ring-inset'
                                : 'bg-accent-primary'
                        "
                        :style="{ width: progress + '%' }"
                    ></div>
                </div>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 gap-3">
                <div class="bg-surface-sunken rounded-xl p-3">
                    <p class="etiquette">Actuel</p>
                    <p class="font-display text-text-main mt-0.5 text-lg font-semibold tabular-nums">
                        {{ nombre(goal.current_value) }}
                        <span class="text-text-muted text-2xs font-medium">{{ uniteDe(goal.current_value) }}</span>
                    </p>
                </div>
                <div class="bg-surface-sunken rounded-xl p-3">
                    <p class="etiquette">Cible</p>
                    <p class="font-display text-text-main mt-0.5 text-lg font-semibold tabular-nums">
                        {{ nombre(goal.target_value) }}
                        <span
                            class="text-text-muted text-2xs font-medium"
                            :class="parSemaine ? 'block leading-tight' : ''"
                            >{{ uniteDeLaCible(goal.target_value) }}</span
                        >
                    </p>
                </div>
            </div>

            <div v-if="goal.deadline" class="etiquette flex items-center gap-1.5 pt-1">
                <GlassIcon name="schedule" size="xs" />
                <span>Échéance : {{ formattedDeadline }}</span>
            </div>

            <!--
              Both names carry the goal title. A screen reader listing the
              controls of a page holding six goals otherwise reads "Modifier,
              Supprimer" six times over with nothing to tell them apart.
            -->
            <div class="border-border flex items-center gap-2 border-t pt-3">
                <Link
                    :href="route('goals.edit', { goal: goal.id })"
                    :dusk="`edit-goal-${goal.id}`"
                    :aria-label="`Modifier l'objectif ${goal.title}`"
                    class="text-text-muted hover:text-text-main focus-visible:ring-accent-primary min-h-touch bg-surface-sunken inline-flex flex-1 items-center justify-center gap-1.5 rounded-xl text-xs font-semibold transition-colors focus-visible:ring-2 focus-visible:outline-none"
                >
                    <GlassIcon name="edit" size="sm" />
                    Modifier
                </Link>
                <button
                    type="button"
                    :dusk="`delete-goal-${goal.id}`"
                    :aria-label="`Supprimer l'objectif ${goal.title}`"
                    class="focus-visible:ring-accent-primary min-h-touch bg-accent-danger/10 text-accent-danger-deep hover:bg-accent-danger/20 inline-flex flex-1 items-center justify-center gap-1.5 rounded-xl text-xs font-semibold transition-colors focus-visible:ring-2 focus-visible:outline-none"
                    @click="emit('delete', goal)"
                >
                    <GlassIcon name="delete" size="sm" />
                    Supprimer
                </button>
            </div>
        </div>
    </GlassCard>
</template>

<script setup>
/**
 * Le formulaire d'une habitude, dans sa modale : nom, description, couleur,
 * icône et objectif hebdomadaire. Il met à jour l'habitude qu'on lui tend,
 * en crée une sinon, et demande à se fermer quand le serveur a dit oui.
 */
import { watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/UI/Modal.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassIconButton from '@/Components/UI/GlassIconButton.vue'

const props = defineProps({
    show: { type: Boolean, default: false },
    habitude: { type: Object, default: null },
})

const emit = defineEmits(['close'])

const form = useForm({
    name: '',
    description: '',
    color: 'bg-palette-ardoise',
    icon: 'check_circle',
    goal_times_per_week: 7,
})

const icons = [
    'check_circle',
    'fitness_center',
    'water_drop',
    'bedtime',
    'restaurant',
    'self_improvement',
    'local_fire_department',
    'bolt',
    'directions_run',
    'monitor_heart',
    'spa',
    'medication',
    'local_cafe',
    'no_drinks',
    'savings',
    'book',
]

/*
 * Les seize couleurs de la palette utilisateur. Elles nomment des jetons de la
 * charte (`--color-palette-*`) et non des nuances de Tailwind : chacune y est
 * calculee pour porter du blanc, ce que la pastille ecrit sans le demander.
 *
 * La valeur est PERSISTEE telle quelle — `habits.color` contient la classe. La
 * renommer demande donc une migration, et il y en a une :
 * `2026_08_26_000000_les_couleurs_d_habitude_nomment_la_charte`.
 */
const colors = [
    'bg-palette-ardoise',
    'bg-palette-rouge',
    'bg-palette-orange',
    'bg-palette-ambre',
    'bg-palette-vert',
    'bg-palette-emeraude',
    'bg-palette-turquoise',
    'bg-palette-cyan',
    'bg-palette-ciel',
    'bg-palette-bleu',
    'bg-palette-indigo',
    'bg-palette-violet',
    'bg-palette-pourpre',
    'bg-palette-fuchsia',
    'bg-palette-rose',
    'bg-palette-framboise',
]

/**
 * Les deux sélecteurs gardent la valeur brute que le gabarit attend (une classe
 * Tailwind, une ligature Material Symbols). Sans ces noms, un lecteur d'écran
 * lisait seize « bouton » identiques pour les couleurs (WCAG 1.4.1) et la
 * ligature brute pour les icônes.
 */
const colorNames = {
    'bg-palette-ardoise': 'Ardoise',
    'bg-palette-rouge': 'Rouge',
    'bg-palette-orange': 'Orange',
    'bg-palette-ambre': 'Ambre',
    'bg-palette-vert': 'Vert',
    'bg-palette-emeraude': 'Émeraude',
    'bg-palette-turquoise': 'Turquoise',
    'bg-palette-cyan': 'Cyan',
    'bg-palette-ciel': 'Bleu ciel',
    'bg-palette-bleu': 'Bleu',
    'bg-palette-indigo': 'Indigo',
    'bg-palette-violet': 'Violet',
    'bg-palette-pourpre': 'Pourpre',
    'bg-palette-fuchsia': 'Fuchsia',
    'bg-palette-rose': 'Rose',
    'bg-palette-framboise': 'Framboise',
}

const iconNames = {
    check_circle: 'Validation',
    fitness_center: 'Musculation',
    water_drop: 'Hydratation',
    bedtime: 'Sommeil',
    restaurant: 'Repas',
    self_improvement: 'Méditation',
    local_fire_department: 'Calories',
    bolt: 'Énergie',
    directions_run: 'Course',
    monitor_heart: 'Cardio',
    spa: 'Bien-être',
    medication: 'Médicament',
    local_cafe: 'Café',
    no_drinks: 'Sans alcool',
    savings: 'Économies',
    book: 'Lecture',
}

/** À l'ouverture : les valeurs de l'habitude tendue, ou celles d'une nouvelle. */
watch(
    () => [props.show, props.habitude],
    ([ouvert, habitude]) => {
        if (!ouvert) return

        if (!habitude) {
            form.reset()
            return
        }

        form.name = habitude.name
        form.description = habitude.description || ''
        form.color = habitude.color
        form.icon = habitude.icon
        form.goal_times_per_week = habitude.goal_times_per_week
    },
)

const submit = () => {
    if (props.habitude) {
        form.put(route('habits.update', props.habitude.id), {
            onSuccess: () => emit('close'),
        })
    } else {
        form.post(route('habits.store'), {
            onSuccess: () => {
                emit('close')
                form.reset()
            },
        })
    }
}
</script>

<template>
    <!--
      Native <dialog> via Modal rather than a hand-rolled overlay: the
      backdrop closed on click but nothing trapped focus, so Tab walked
      straight out of the form and into the page behind it.
    -->
    <Modal :show="show" max-width="lg" aria-labelledby="habit-form-title" @close="$emit('close')">
        <div class="p-6">
            <div class="mb-4 flex items-center justify-between">
                <h3 id="habit-form-title" class="text-text-main text-xl font-bold">
                    {{ habitude ? 'Modifier' : 'Nouvelle Habitude' }}
                </h3>
                <GlassIconButton icon="close" label="Fermer le formulaire" @click="$emit('close')" />
            </div>

            <form @submit.prevent="submit" class="space-y-4">
                <GlassInput
                    v-model="form.name"
                    label="Nom"
                    placeholder="Ex: Boire 2L d'eau"
                    :error="form.errors.name"
                    required
                />

                <GlassInput
                    v-model="form.description"
                    label="Description (optionnel)"
                    placeholder="Détails..."
                    :error="form.errors.description"
                />

                <div>
                    <!-- A <label> with no `for` labels nothing. The swatches are
                         buttons, so the group is what needs naming. -->
                    <span id="habit-color-label" class="text-text-muted mb-1 block text-sm font-medium"> Couleur </span>
                    <div class="flex flex-wrap gap-2" role="group" aria-labelledby="habit-color-label">
                        <button
                            v-for="color in colors"
                            :key="color"
                            type="button"
                            @click="form.color = color"
                            :aria-label="colorNames[color]"
                            :aria-pressed="form.color === color"
                            :dusk="`habit-color-${color}`"
                            class="focus-visible:ring-accent-primary h-8 w-8 rounded-full border-2 transition focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                            :class="[color, form.color === color ? 'border-text-main scale-110' : 'border-transparent']"
                        ></button>
                    </div>
                </div>

                <div>
                    <span id="habit-icon-label" class="text-text-muted mb-1 block text-sm font-medium">Icône</span>
                    <div class="flex flex-wrap gap-2" role="group" aria-labelledby="habit-icon-label">
                        <button
                            v-for="icon in icons"
                            :key="icon"
                            type="button"
                            @click="form.icon = icon"
                            :aria-label="iconNames[icon]"
                            :aria-pressed="form.icon === icon"
                            :dusk="`habit-icon-${icon}`"
                            class="focus-visible:ring-accent-primary hover:bg-surface-sunken flex size-11 items-center justify-center rounded-lg border-2 transition focus-visible:ring-2 focus-visible:outline-none"
                            :class="[
                                form.icon === icon
                                    ? 'border-accent-primary bg-accent-primary/10 text-accent-primary-deep'
                                    : 'text-text-muted border-transparent',
                            ]"
                        >
                            <!-- Without this the button announces the raw ligature. -->
                            <span class="material-symbols-outlined" aria-hidden="true">{{ icon }}</span>
                        </button>
                    </div>
                </div>

                <GlassInput
                    v-model="form.goal_times_per_week"
                    type="number"
                    min="1"
                    max="7"
                    label="Objectif (fois par semaine)"
                    :error="form.errors.goal_times_per_week"
                />

                <div class="flex justify-end gap-3 pt-4">
                    <GlassButton type="button" variant="secondary" @click="$emit('close')">Annuler</GlassButton>
                    <GlassButton type="submit" variant="primary" :loading="form.processing">Enregistrer</GlassButton>
                </div>
            </form>
        </div>
    </Modal>
</template>

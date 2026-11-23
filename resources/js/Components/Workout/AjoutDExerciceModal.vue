<script setup>
/**
 * Choisir un exercice de la bibliothèque, ou en créer un sur le champ.
 *
 * Un tiroir qui monte du bas, comme le bilan de séance : la recherche et les
 * pastilles de catégorie en haut, la liste groupée par catégorie dessous.
 * « Créer » est la première ligne de la liste, toujours là : sans recherche
 * elle ouvre le formulaire, avec une recherche elle reprend le texte tapé
 * comme nom, qu'il y ait des résultats ou non. La création parle au serveur
 * elle-même et rend l'exercice au parent, qui l'ajoute à sa liste puis à la
 * séance.
 *
 * La recherche est gardée dans le stockage local : on revient souvent chercher
 * le même exercice d'une séance à l'autre. La déconnexion l'efface
 * (`Utils/rechercheDExercice`, #2050).
 */
import { computed, nextTick, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/UI/Modal.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassChip from '@/Components/UI/GlassChip.vue'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import GlassIconButton from '@/Components/UI/GlassIconButton.vue'
import ChampsDUnExercice from '@/Components/Exercises/ChampsDUnExercice.vue'
import { CATEGORY_COLORS, EXERCISE_CATEGORIES } from '@/Utils/constants'
import { sansAccents } from '@/Utils/sansAccents'
import { garderLaRecherche, lireLaRecherche } from '@/Utils/rechercheDExercice'
import { triggerHaptic } from '@/composables/useHaptics'

const props = defineProps({
    show: { type: Boolean, default: false },
    exercises: { type: Array, default: () => [] },
})

const emit = defineEmits(['close', 'add', 'created'])

const searchQuery = ref(lireLaRecherche())
watch(searchQuery, garderLaRecherche)

const showCreateForm = ref(false)
const createExerciseForm = useForm({ name: '', type: 'strength', category: '' })

const TOUTES = 'all'
const SANS_CATEGORIE = 'Autres'

/** La catégorie retenue par les pastilles : `all`, ou le nom d'une catégorie. */
const categorie = ref(TOUTES)

/** Ce qui a été tapé, sans les espaces autour : une recherche d'espaces ne cherche rien. */
const requete = computed(() => searchQuery.value.trim())

const categorieDe = (exercise) => exercise.category || SANS_CATEGORIE

/** Les catégories que porte la bibliothèque, dans l'ordre de la charte, les autres ensuite. */
const categories = computed(() => {
    const presentes = new Set(props.exercises.map(categorieDe))
    const connues = EXERCISE_CATEGORIES.filter((nom) => presentes.has(nom))
    const autres = [...presentes]
        .filter((nom) => !EXERCISE_CATEGORIES.includes(nom))
        .sort((a, b) => a.localeCompare(b, 'fr'))

    return [...connues, ...autres]
})

const filteredExercises = computed(() => {
    const q = sansAccents(requete.value)

    return props.exercises.filter(
        (e) =>
            (categorie.value === TOUTES || categorieDe(e) === categorie.value) &&
            (!q || sansAccents(e.name).includes(q)),
    )
})

/** Les résultats, groupés par catégorie, chaque groupe par ordre alphabétique. */
const groupes = computed(() =>
    categories.value
        .map((nom) => ({
            nom,
            exercises: filteredExercises.value
                .filter((e) => categorieDe(e) === nom)
                .sort((a, b) => a.name.localeCompare(b.name, 'fr')),
        }))
        .filter((groupe) => groupe.exercises.length > 0),
)

/** Sans exercice et sans recherche, c'est l'état vide qui propose de créer, pas la première ligne. */
const ligneDeCreation = computed(() => props.exercises.length > 0 || requete.value !== '')

const choisir = (exerciseId) => {
    emit('add', exerciseId)
    searchQuery.value = ''
    categorie.value = TOUTES
}

const quickCreate = () => {
    createExerciseForm.name = requete.value
    createExerciseForm.category =
        categorie.value === TOUTES || categorie.value === SANS_CATEGORIE ? '' : categorie.value
    createExerciseForm.clearErrors()
    showCreateForm.value = true

    nextTick(() => document.querySelector('[dusk="new-exercise-name"]')?.focus())
}

/** Chaque fermeture ou retour invalide la création en vol : sa réponse n'ajoutera plus rien à la séance. */
let generationDeCreation = 0

const retourALaListe = () => {
    generationDeCreation++
    createExerciseForm.processing = false
    showCreateForm.value = false
}

/** Échap dans le formulaire revient à la liste : le navigateur ne doit pas fermer le dialogue en plus. */
const surEchap = (event) => {
    if (showCreateForm.value) {
        event.preventDefault()
        retourALaListe()
    }
}

const fermer = () => {
    generationDeCreation++
    createExerciseForm.processing = false
    showCreateForm.value = false
    searchQuery.value = ''
    categorie.value = TOUTES
    emit('close')
}

const createAndAddExercise = async () => {
    if (createExerciseForm.processing) {
        return
    }

    const generation = generationDeCreation

    createExerciseForm.processing = true
    createExerciseForm.clearErrors()
    try {
        const response = await fetch(route('exercises.store'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({
                name: createExerciseForm.name,
                type: createExerciseForm.type,
                category: createExerciseForm.category,
            }),
        })
        if (response.ok) {
            const { exercise } = await response.json()

            emit('created', exercise)

            if (generation === generationDeCreation) {
                choisir(exercise.id)
                showCreateForm.value = false
            }

            return
        }

        // Autre chose qu'un 2xx laissait la modale ouverte sans un mot, et
        // l'utilisateur tapait encore. Les erreurs de validation remontent.
        if (generation !== generationDeCreation) {
            return
        }

        if (response.status === 422) {
            const { errors = {} } = await response.json()

            Object.entries(errors).forEach(([field, messages]) => {
                createExerciseForm.setError(field, [].concat(messages)[0])
            })
        } else {
            createExerciseForm.setError('name', 'La création a échoué. Réessaie dans un instant.')
        }

        triggerHaptic('error')
    } catch (e) {
        console.error(e)

        if (generation !== generationDeCreation) {
            return
        }

        createExerciseForm.setError('name', 'Connexion impossible. Vérifie ta connexion réseau.')
        triggerHaptic('error')
    } finally {
        if (generation === generationDeCreation) {
            createExerciseForm.processing = false
        }
    }
}

defineExpose({ searchQuery, categorie, showCreateForm, createExerciseForm, quickCreate, createAndAddExercise, fermer })
</script>

<template>
    <Modal :show="show" position="tiroir" max-width="lg" aria-labelledby="add-exercise-title" @close="fermer">
        <div class="flex min-h-0 flex-1 flex-col" @keydown.esc="surEchap">
            <div class="flex shrink-0 items-center gap-3 px-6 pb-4">
                <GlassIconButton
                    v-if="showCreateForm"
                    icon="arrow_back"
                    label="Retour à la liste"
                    @click="retourALaListe"
                />
                <h2 id="add-exercise-title" class="titre-plat min-w-0 flex-1">
                    {{ showCreateForm ? 'Nouvel exercice' : 'Ajouter un exercice' }}
                </h2>
                <GlassButton
                    v-if="showCreateForm"
                    type="submit"
                    form="nouvel-exercice"
                    variant="primary"
                    size="sm"
                    :loading="createExerciseForm.processing"
                    dusk="submit-new-exercise"
                >
                    Créer
                </GlassButton>
                <GlassIconButton v-else icon="close" label="Fermer" dusk="fermer-ajout-exercice" @click="fermer" />
            </div>

            <template v-if="!showCreateForm">
                <div class="shrink-0 space-y-3 px-6 pb-3">
                    <GlassInput
                        id="search-workout-exercise"
                        v-model="searchQuery"
                        type="search"
                        size="lg"
                        label="Rechercher un exercice"
                        hide-label
                        placeholder="Rechercher..."
                    />
                    <div v-if="categories.length > 1" class="hide-scrollbar -mx-6 flex gap-2 overflow-x-auto px-6 pb-1">
                        <GlassChip
                            dusk="categorie-tous"
                            icon="apps"
                            :active="categorie === TOUTES"
                            @click="categorie = TOUTES"
                        >
                            Tous
                        </GlassChip>
                        <GlassChip
                            v-for="nom in categories"
                            :key="nom"
                            :dusk="`categorie-${nom}`"
                            :active="categorie === nom"
                            :classe-active="CATEGORY_COLORS[nom] ?? 'category-fill-other'"
                            @click="categorie = nom"
                        >
                            {{ nom }}
                        </GlassChip>
                    </div>
                </div>

                <div class="pb-feuille-4 min-h-0 flex-1 overflow-y-auto px-4" dusk="liste-des-exercices">
                    <GlassEmptyState
                        v-if="exercises.length === 0 && !requete"
                        taille="ligne"
                        title="Aucun exercice, crée le premier"
                        action-label="Créer un exercice"
                        action-id="empty-create-exercise"
                        @action="quickCreate"
                    />

                    <button
                        v-if="ligneDeCreation"
                        type="button"
                        class="focus-visible:ring-accent-primary hover:bg-surface-card/50 flex min-h-14 w-full items-center gap-3 rounded-2xl px-2 py-2 text-left transition focus-visible:ring-2 focus-visible:outline-none"
                        dusk="quick-create-exercise"
                        @click="quickCreate"
                    >
                        <span
                            class="bg-accent-primary/10 text-accent-primary flex size-9 shrink-0 items-center justify-center rounded-xl"
                        >
                            <GlassIcon name="add" size="sm" />
                        </span>
                        <span class="text-text-main min-w-0 flex-1 truncate font-medium">
                            {{ requete ? `Créer « ${requete} »` : 'Créer un exercice' }}
                        </span>
                    </button>

                    <p
                        v-if="ligneDeCreation && filteredExercises.length === 0"
                        class="text-text-muted px-4 py-6 text-center text-sm"
                    >
                        {{ requete ? `Aucun résultat pour « ${requete} »` : 'Aucun résultat pour ce filtre' }}
                    </p>

                    <!-- Un bouton par exercice : ajouter est l'action première de cet écran, et
                         elle doit rester à portée du clavier. Des spans plutôt que h4/p : du
                         contenu de flux est invalide dans un bouton. -->
                    <section v-for="groupe in groupes" :key="groupe.nom" :aria-label="groupe.nom" class="mt-3">
                        <h3 class="etiquette flex items-baseline justify-between px-4 pb-1">
                            {{ groupe.nom }}
                            <span class="tabular-nums">{{ groupe.exercises.length }}</span>
                        </h3>
                        <button
                            v-for="exercise in groupe.exercises"
                            :key="exercise.id"
                            type="button"
                            :dusk="`select-exercise-${exercise.id}`"
                            class="hover:bg-surface-sunken focus-visible:ring-accent-primary min-h-touch flex w-full cursor-pointer items-center justify-between gap-3 rounded-2xl px-4 py-2 text-left transition focus-visible:ring-2 focus-visible:outline-none"
                            @click="choisir(exercise.id)"
                        >
                            <span class="text-text-main font-medium">{{ exercise.name }}</span>
                            <GlassIcon name="add" size="sm" class="text-text-muted" />
                        </button>
                    </section>
                </div>
            </template>

            <form
                id="nouvel-exercice"
                v-else
                class="pb-feuille-6 min-h-0 flex-1 space-y-6 overflow-y-auto px-6"
                @submit.prevent="createAndAddExercise"
            >
                <ChampsDUnExercice
                    v-model:name="createExerciseForm.name"
                    v-model:type="createExerciseForm.type"
                    v-model:category="createExerciseForm.category"
                    :errors="createExerciseForm.errors"
                    dusk-nom="new-exercise-name"
                    dusk-type="new-exercise-type"
                    dusk-categorie="new-exercise-category"
                />
            </form>
        </div>
    </Modal>
</template>

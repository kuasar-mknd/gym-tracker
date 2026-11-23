<script setup>
/**
 * Les trois champs d'un exercice : nom, type, catégorie. La bibliothèque et le
 * tiroir « Ajouter un exercice » créent un exercice avec les mêmes champs ;
 * chacun garde son formulaire Inertia (`v-model:name`, `:type`, `:category`)
 * et ses identifiants de test, les champs ne s'écrivent qu'une fois.
 */
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassSelect from '@/Components/UI/GlassSelect.vue'
import { EXERCISE_CATEGORIES, EXERCISE_TYPES } from '@/Utils/constants'

const name = defineModel('name', { type: String, default: '' })
const type = defineModel('type', { type: String, default: 'strength' })
const category = defineModel('category', { type: String, default: '' })

defineProps({
    /** Les erreurs du formulaire Inertia, par champ. */
    errors: { type: Object, default: () => ({}) },
    duskNom: { type: String, default: undefined },
    duskType: { type: String, default: undefined },
    duskCategorie: { type: String, default: undefined },
})

const categories = EXERCISE_CATEGORIES.map((categorie) => ({ value: categorie, label: categorie }))
</script>

<template>
    <div class="space-y-4">
        <GlassInput
            v-model="name"
            name="name"
            required
            label="Nom de l'exercice"
            placeholder="Ex. : Développé couché"
            :dusk="duskNom"
            :error="errors.name"
        />
        <div class="grid grid-cols-2 gap-4">
            <GlassSelect
                v-model="type"
                name="type"
                label="Type"
                :options="EXERCISE_TYPES"
                placeholder=""
                :dusk="duskType"
                :error="errors.type"
            />
            <GlassSelect
                v-model="category"
                name="category"
                label="Catégorie"
                :options="categories"
                empty-label="— Aucune —"
                :dusk="duskCategorie"
                :error="errors.category"
            />
        </div>
    </div>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import TemplateForm from '@/Components/Templates/TemplateForm.vue'
import { Head } from '@inertiajs/vue3'

defineProps({
    exercises: {
        type: Array,
        default: () => [],
    },
    bornesDuModele: {
        type: Object,
        default: null,
    },
    bornesDUneSerie: {
        type: Object,
        default: null,
    },
})
</script>

<template>
    <Head title="Nouveau Modèle" />

    <AuthenticatedLayout page-title="Nouveau Modèle" show-back back-route="templates.index">
        <TemplateForm :exercises="exercises" :bornes-du-modele="bornesDuModele" :bornes-d-une-serie="bornesDUneSerie" />
    </AuthenticatedLayout>
</template>

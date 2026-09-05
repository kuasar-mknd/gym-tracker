<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import TemplateForm from '@/Components/Templates/TemplateForm.vue'
import { Head } from '@inertiajs/vue3'

defineProps({
    template: {
        type: Object,
        required: true,
    },
    exercises: {
        type: Array,
        default: () => [],
    },
})
</script>

<template>
    <Head title="Modifier le Modèle" />

    <AuthenticatedLayout page-title="Modifier le Modèle" show-back back-route="templates.index">
        <TemplateForm :template="template" :exercises="exercises" />
    </AuthenticatedLayout>
</template>

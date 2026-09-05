<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import TemplateForm from '@/Components/Templates/TemplateForm.vue'
import { Head } from '@inertiajs/vue3'

defineProps({
    exercises: {
        type: Array,
        default: () => [],
    },
})
</script>

<template>
    <Head title="Nouveau Modèle" />

    <AuthenticatedLayout page-title="Nouveau Modèle" show-back back-route="templates.index">
        <TemplateForm :exercises="exercises" />
    </AuthenticatedLayout>
</template>

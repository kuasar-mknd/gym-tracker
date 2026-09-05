<script setup>
import { couleurDeCategorie } from '@/Utils/couleurs'
import { computed } from 'vue'
import BaseChart from './BaseChart.vue'

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const labels = computed(() => props.data.map((item) => item.category))

const datasets = computed(() => [
    {
        data: props.data.map((item) => item.volume),
        /*
         * Chaque part prend la couleur de SA catégorie, au lieu d'une
         * liste posée dans l'ordre d'arrivée.
         *
         * La liste était fragile de deux façons. Elle supposait l'ordre
         * des données — un groupe musculaire absent décalait toutes les
         * couleurs suivantes — et sa longueur devait suivre à la main
         * celle de `ExerciseCategory` : une septième part avait déjà
         * manqué de couleur et retombait sur le noir de Chart.js.
         *
         * Lue depuis la catégorie, la couleur est la même ici, dans la
         * bordure des cartes et dans l'anneau de la bibliothèque, sans
         * que rien ne les tienne en accord.
         */
        backgroundColor: props.data.map((item) => couleurDeCategorie(item.category)),
    },
])
</script>

<template>
    <BaseChart type="doughnut" :labels="labels" :datasets="datasets" hauteur="h-52" :infobulle="{ boxPadding: 8 }" />
</template>

<script setup>
import { Doughnut } from 'vue-chartjs'
import { couleurDeCategorie, jeton, jetonTransparent } from '@/Utils/couleurs'
import { Chart as ChartJS, Title, Tooltip, Legend, ArcElement } from 'chart.js'
import { computed } from 'vue'

ChartJS.register(Title, Tooltip, Legend, ArcElement)

const props = defineProps({
    data: {
        type: Array,
        required: true,
    },
})

const chartData = computed(() => {
    return {
        labels: props.data.map((item) => item.category),
        datasets: [
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
                borderWidth: 0,
                hoverOffset: 15,
                borderRadius: 4,
            },
        ],
    }
})

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    cutout: '70%',
    plugins: {
        legend: {
            position: 'right',
            labels: {
                color: jeton('text-muted'),
                font: { size: 10, weight: 'bold' },
                padding: 15,
                usePointStyle: true,
                pointStyle: 'circle',
            },
        },
        tooltip: {
            backgroundColor: jetonTransparent('surface-card', 0.9),
            titleColor: jeton('text-main'),
            bodyColor: jeton('text-main'),
            padding: 12,
            cornerRadius: 12,
            boxPadding: 8,
            borderWidth: 1,
            borderColor: jetonTransparent('shadow-cast', 0.05),
        },
    },
}))
</script>

<template>
    <div class="h-52 w-full">
        <Doughnut :data="chartData" :options="chartOptions" />
    </div>
</template>

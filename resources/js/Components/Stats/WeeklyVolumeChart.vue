<script setup>
import { Line } from 'vue-chartjs'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler
} from 'chart.js'
import { computed, ref } from 'vue'

ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler
)

const props = defineProps({
  data: {
    type: Array,
    required: true
  }
})

const chartData = computed(() => {
  const labels = props.data.map(d => d.day_label)
  const volumes = props.data.map(d => d.volume)

  return {
    labels,
    datasets: [
      {
        label: 'Volume',
        data: volumes,
        fill: true,
        backgroundColor: (context) => {
          const chart = context.chart
          const { ctx, chartArea } = chart
          if (!chartArea) return null

          const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom)
          gradient.addColorStop(0, 'rgba(255, 85, 0, 0.2)')
          gradient.addColorStop(1, 'rgba(255, 85, 0, 0)')

          return gradient
        },
        borderColor: (context) => {
          const chart = context.chart
          const { ctx, chartArea } = chart
          if (!chartArea) return null

          const gradient = ctx.createLinearGradient(chartArea.left, 0, chartArea.right, 0)
          gradient.addColorStop(0, '#FF5500')
          gradient.addColorStop(0.5, '#FF0080')
          gradient.addColorStop(1, '#8800FF')

          return gradient
        },
        borderWidth: 4,
        tension: 0.4,
        pointBackgroundColor: '#FFFFFF',
        pointBorderColor: '#FF0080',
        pointBorderWidth: 2,
        pointRadius: 4,
        pointHoverRadius: 6
      }
    ]
  }
})

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: {
      backgroundColor: 'rgba(255, 255, 255, 0.9)',
      titleColor: '#1e293b',
      bodyColor: '#1e293b',
      borderColor: 'rgba(255, 85, 0, 0.1)',
      borderWidth: 1,
      padding: 10,
      displayColors: false,
      callbacks: {
        label: (context) => `${context.parsed.y} kg`
      }
    }
  },
  scales: {
    x: {
      grid: { display: false },
      ticks: {
        color: '#94a3b8',
        font: { size: 10, weight: 'bold', family: 'sans-serif' },
        padding: 10
      },
      border: { display: false }
    },
    y: {
      display: false,
      beginAtZero: true
    }
  },
  interaction: {
      mode: 'index',
      intersect: false,
  },
  layout: {
      padding: {
          left: -10,
          right: -10,
          bottom: 0,
          top: 10
      }
  }
}
</script>

<template>
  <div class="h-48 w-full">
    <Line :data="chartData" :options="chartOptions" />
  </div>
</template>

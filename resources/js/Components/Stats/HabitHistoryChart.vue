<script setup>
import { computed } from 'vue'
import {
    Chart as ChartJS,
    Title,
    Tooltip,
    Legend,
    LineElement,
    PointElement,
    CategoryScale,
    LinearScale,
    Filler,
} from 'chart.js'
import { Line } from 'vue-chartjs'
import GlassCard from '@/Components/UI/GlassCard.vue'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend, Filler)

const props = defineProps({
    habits: {
        type: Array,
        required: true,
    },
})

const chartData = computed(() => {
    // Generate dates for the last 30 days
    const dates = []
    const today = new Date()
    for (let i = 29; i >= 0; i--) {
        const d = new Date(today)
        d.setDate(today.getDate() - i)
        // Use local date string instead of ISO to avoid timezone shifts
        const year = d.getFullYear()
        const month = String(d.getMonth() + 1).padStart(2, '0')
        const day = String(d.getDate()).padStart(2, '0')
        dates.push(`${year}-${month}-${day}`)
    }

    // Calculate completion rate per day
    // Rate = (Total completed habits that day / Total active habits) * 100
    // Note: We use the current number of habits as the denominator.
    // This is an approximation since we don't track historical habit creation dates in this context,
    // but it serves the purpose of showing consistency trends.

    const totalHabits = props.habits.length
    if (totalHabits === 0) return { labels: [], datasets: [] }

    const completionRates = dates.map((date) => {
        let completedCount = 0
        props.habits.forEach((habit) => {
            if (habit.logs && habit.logs.some((log) => log.date === date)) {
                completedCount++
            }
        })
        return Math.round((completedCount / totalHabits) * 100)
    })

    // Format labels
    const labels = dates.map((dateStr) => {
        const date = new Date(dateStr)
        return date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })
    })

    return {
        labels: labels,
        datasets: [
            {
                label: 'Taux de réussite (%)',
                data: completionRates,
                borderColor: '#34d399', // Emerald 400
                backgroundColor: (context) => {
                    const ctx = context.chart.ctx
                    const gradient = ctx.createLinearGradient(0, 0, 0, 300)
                    gradient.addColorStop(0, 'rgba(52, 211, 153, 0.4)') // Emerald
                    gradient.addColorStop(1, 'rgba(6, 182, 212, 0.0)') // Cyan transparent
                    return gradient
                },
                borderWidth: 3,
                pointBackgroundColor: '#34d399',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointRadius: 0,
                pointHoverRadius: 6,
                fill: true,
                tension: 0.4, // Smooth curve
            },
        ],
    }
})

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            display: false,
        },
        title: {
            display: false,
        },
        tooltip: {
            backgroundColor: 'rgba(255, 255, 255, 0.9)',
            titleColor: '#0F172A',
            bodyColor: '#0F172A',
            borderColor: 'rgba(0, 0, 0, 0.05)',
            borderWidth: 1,
            padding: 12,
            cornerRadius: 12,
            displayColors: false,
            callbacks: {
                label: (context) => `${context.raw}% de réussite`,
            },
        },
    },
    scales: {
        y: {
            beginAtZero: true,
            max: 100,
            grid: {
                color: 'rgba(255, 255, 255, 0.1)',
                drawBorder: false,
            },
            ticks: {
                stepSize: 25,
                color: 'rgba(255, 255, 255, 0.6)',
                font: {
                    size: 10,
                    family: 'sans-serif',
                },
                callback: (value) => `${value}%`,
            },
            border: {
                display: false,
            },
        },
        x: {
            grid: {
                display: false,
            },
            ticks: {
                color: 'rgba(255, 255, 255, 0.6)',
                font: {
                    size: 10,
                },
                maxTicksLimit: 6,
            },
            border: {
                display: false,
            },
        },
    },
    interaction: {
        intersect: false,
        mode: 'index',
    },
}
</script>

<template>
    <GlassCard variant="iridescent" padding="p-6" class="group relative overflow-hidden">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="font-display text-xs font-black tracking-[0.2em] text-emerald-400 uppercase">
                Régularité (30 jours)
            </h3>
            <div class="text-right">
                <span class="text-xs font-bold text-white/60">Moyenne</span>
                <p class="font-display text-2xl font-black text-white">
                    {{
                        Math.round(
                            chartData.datasets[0]?.data.reduce((a, b) => a + b, 0) /
                                chartData.datasets[0]?.data.length || 0,
                        )
                    }}%
                </p>
            </div>
        </div>

        <div class="relative h-48 w-full transition-transform duration-500 group-hover:scale-[1.02]">
            <Line :data="chartData" :options="chartOptions" />
        </div>

        <!-- Decorative Glow -->
        <div
            class="pointer-events-none absolute -top-10 -right-10 h-32 w-32 rounded-full bg-emerald-500/10 blur-3xl transition-all duration-700 group-hover:bg-emerald-500/20"
        ></div>
    </GlassCard>
</template>

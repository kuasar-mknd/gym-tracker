<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, useForm } from '@inertiajs/vue3'
import DashboardHeader from '@/Components/Dashboard/DashboardHeader.vue'
import QuickActions from '@/Components/Dashboard/QuickActions.vue'
import WeeklyVolumeSection from '@/Components/Dashboard/WeeklyVolumeSection.vue'
import DurationSection from '@/Components/Dashboard/DurationSection.vue'
import TimeOfDaySection from '@/Components/Dashboard/TimeOfDaySection.vue'
import RecentVolumeSection from '@/Components/Dashboard/RecentVolumeSection.vue'
import RecentActivity from '@/Components/Dashboard/RecentActivity.vue'
import GoalsSummary from '@/Components/Dashboard/GoalsSummary.vue'
import RecentPRs from '@/Components/Dashboard/RecentPRs.vue'

/**
 * Dashboard - Command Center
 * Pure composition page using specialized sub-components.
 */
defineProps({
    latestWeight: { type: Number, default: null },
    recentWorkouts: { type: Array, default: () => [] },
    recentPRs: { type: Array, default: () => [] },
    activeGoals: { type: Array, default: () => [] },
    weeklyVolumeStats: { type: Object, default: () => ({ current_week_volume: 0, percentage: 0 }) },
    weeklyVolumeTrend: { type: Array, default: () => [] },
    durationDistribution: { type: Array, default: () => [] },
    timeOfDayDistribution: { type: Array, default: () => [] },
})

const form = useForm({})

const startWorkout = () => {
    form.post(route('workouts.store'))
}
</script>

<template>
    <Head title="Accueil" />

    <AuthenticatedLayout>
        <div class="space-y-6">
            <DashboardHeader :user="$page.props.auth.user" />

            <QuickActions :processing="form.processing" @start-workout="startWorkout" />

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <WeeklyVolumeSection
                    :weekly-volume-stats="weeklyVolumeStats"
                    :weekly-volume-trend="weeklyVolumeTrend"
                />

                <DurationSection :duration-distribution="durationDistribution" />
            </div>

            <TimeOfDaySection :time-of-day-distribution="timeOfDayDistribution" />

            <RecentVolumeSection :recent-workouts="recentWorkouts" />

            <RecentActivity
                :recent-workouts="recentWorkouts"
                :processing="form.processing"
                @start-workout="startWorkout"
            />

            <GoalsSummary :active-goals="activeGoals" />

            <RecentPRs :recent-p-rs="recentPRs" />
        </div>
    </AuthenticatedLayout>
</template>

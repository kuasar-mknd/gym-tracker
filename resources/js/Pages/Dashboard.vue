<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, useForm, Deferred } from '@inertiajs/vue3'
import DashboardHeader from '@/Components/Dashboard/DashboardHeader.vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'
import QuickActions from '@/Components/Dashboard/QuickActions.vue'
import WeeklyVolumeSection from '@/Components/Dashboard/WeeklyVolumeSection.vue'
import DurationSection from '@/Components/Dashboard/DurationSection.vue'
import TimeOfDaySection from '@/Components/Dashboard/TimeOfDaySection.vue'
import RecentVolumeSection from '@/Components/Dashboard/RecentVolumeSection.vue'
import RecentExercisesSection from '@/Components/Dashboard/RecentExercisesSection.vue'
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

    // ⚡ Bolt: Consolidated deferred props
    analyticalStats: {
        type: Object,
        default: () => ({
            weeklyVolume: { stats: { current_week_volume: 0, percentage: 0 }, trend: [] },
            workoutDistributions: { duration: [], time_of_day: [] },
        }),
    },
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

            <Deferred data="analyticalStats">
                <template #fallback>
                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <GlassSkeleton class="h-64 w-full" />
                        <GlassSkeleton class="h-64 w-full" />
                    </div>
                    <GlassSkeleton class="mt-4 h-64 w-full" />
                </template>

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <WeeklyVolumeSection
                        :weekly-volume-stats="analyticalStats?.weeklyVolume?.stats"
                        :weekly-volume-trend="analyticalStats?.weeklyVolume?.trend"
                    />

                    <DurationSection :duration-distribution="analyticalStats?.workoutDistributions?.duration" />
                </div>

                <TimeOfDaySection :time-of-day-distribution="analyticalStats?.workoutDistributions?.time_of_day" />
            </Deferred>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <RecentVolumeSection :recent-workouts="recentWorkouts" />
                <RecentExercisesSection :recent-workouts="recentWorkouts" />
            </div>

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

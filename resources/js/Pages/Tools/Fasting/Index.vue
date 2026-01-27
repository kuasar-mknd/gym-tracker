<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted } from 'vue';
import GlassCard from '@/Components/UI/GlassCard.vue';
import GlassButton from '@/Components/UI/GlassButton.vue';

const props = defineProps({
    activeFast: Object,
    history: Array,
});

const form = useForm({
    target_duration_minutes: 960, // 16 hours
    type: '16:8',
});

const elapsedSeconds = ref(0);
const progress = ref(0);
let timerInterval = null;

const formatDuration = (minutes) => {
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return `${h}h ${m}m`;
};

const formatTime = (seconds) => {
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    return `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
};

const calculateProgress = () => {
    if (!props.activeFast) return;

    const start = new Date(props.activeFast.start_time).getTime();
    const now = new Date().getTime();
    const diff = Math.floor((now - start) / 1000); // seconds
    elapsedSeconds.value = diff;

    const targetSeconds = props.activeFast.target_duration_minutes * 60;
    progress.value = Math.min((diff / targetSeconds) * 100, 100);
};

onMounted(() => {
    if (props.activeFast) {
        calculateProgress();
        timerInterval = setInterval(calculateProgress, 1000);
    }
});

onUnmounted(() => {
    if (timerInterval) clearInterval(timerInterval);
});

const startFast = (duration, type) => {
    form.target_duration_minutes = duration;
    form.type = type;
    form.post(route('tools.fasting.store'), {
        onSuccess: () => {
            calculateProgress();
            timerInterval = setInterval(calculateProgress, 1000);
        }
    });
};

const endFast = () => {
    router.patch(route('tools.fasting.update', props.activeFast.id), {}, {
        onSuccess: () => {
            clearInterval(timerInterval);
            elapsedSeconds.value = 0;
            progress.value = 0;
        }
    });
};

const deleteFast = (id) => {
    if(confirm('Are you sure?')) {
        router.delete(route('tools.fasting.destroy', id));
    }
}
</script>

<template>
    <Head title="Fasting Tracker" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                Fasting Tracker
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

                <!-- Active Fast Section -->
                <GlassCard v-if="activeFast" class="flex flex-col items-center justify-center py-10">
                    <div class="relative w-64 h-64 mb-6">
                        <!-- SVG Circle Timer -->
                        <svg class="w-full h-full transform -rotate-90" viewBox="0 0 100 100">
                            <circle cx="50" cy="50" r="45" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="8" />
                            <circle cx="50" cy="50" r="45" fill="none" stroke="#6366f1" stroke-width="8"
                                stroke-dasharray="283"
                                :stroke-dashoffset="283 - (283 * progress / 100)"
                                class="transition-all duration-1000 ease-linear"
                            />
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center text-text-main">
                            <span class="text-4xl font-bold font-mono">{{ formatTime(elapsedSeconds) }}</span>
                            <span class="text-sm text-text-muted">Target: {{ formatDuration(activeFast.target_duration_minutes) }}</span>
                            <span class="text-xs text-text-muted mt-1">{{ Math.round(progress) }}%</span>
                        </div>
                    </div>

                    <GlassButton @click="endFast" variant="danger" class="w-full sm:w-auto">
                        End Fast
                    </GlassButton>
                </GlassCard>

                <!-- Start Fast Options -->
                <GlassCard v-else class="text-center py-10">
                    <h3 class="text-2xl font-bold text-text-main mb-6">Start a Fast</h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 max-w-2xl mx-auto">
                        <GlassButton @click="startFast(960, '16:8')" variant="secondary">
                            <div class="flex flex-col">
                                <span class="font-bold text-lg">16:8</span>
                                <span class="text-xs opacity-70">Standard</span>
                            </div>
                        </GlassButton>
                        <GlassButton @click="startFast(1080, '18:6')" variant="secondary">
                            <div class="flex flex-col">
                                <span class="font-bold text-lg">18:6</span>
                                <span class="text-xs opacity-70">Intermediate</span>
                            </div>
                        </GlassButton>
                        <GlassButton @click="startFast(1200, '20:4')" variant="secondary">
                            <div class="flex flex-col">
                                <span class="font-bold text-lg">20:4</span>
                                <span class="text-xs opacity-70">Warrior</span>
                            </div>
                        </GlassButton>
                         <GlassButton @click="startFast(1440, '24h')" variant="secondary">
                            <div class="flex flex-col">
                                <span class="font-bold text-lg">24h</span>
                                <span class="text-xs opacity-70">Full Day</span>
                            </div>
                        </GlassButton>
                    </div>
                </GlassCard>

                <!-- History -->
                <div v-if="history.length > 0">
                    <h3 class="text-lg font-semibold text-text-main mb-4">History</h3>
                    <div class="grid gap-4">
                        <GlassCard v-for="fast in history" :key="fast.id" class="flex justify-between items-center" padding="p-4">
                            <div>
                                <div class="text-text-main font-semibold">{{ fast.type }}</div>
                                <div class="text-sm text-text-muted">
                                    {{ new Date(fast.start_time).toLocaleDateString() }} •
                                    {{ formatDuration(Math.floor((new Date(fast.end_time) - new Date(fast.start_time)) / 1000 / 60)) }}
                                </div>
                            </div>
                            <button @click="deleteFast(fast.id)" class="text-red-400 hover:text-red-300">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
                                </svg>
                            </button>
                        </GlassCard>
                    </div>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>

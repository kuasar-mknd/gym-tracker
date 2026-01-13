<script setup>
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import { Head, Link } from '@inertiajs/vue3'

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
})
</script>

<template>
    <Head title="Bienvenue" />

    <div class="flex min-h-screen flex-col items-center justify-center px-6 py-12">
        <!-- Hero -->
        <div class="max-w-lg animate-fade-in text-center">
            <h1 class="text-gradient text-5xl font-bold">GymTracker</h1>
            <p class="mt-4 text-lg text-white/70">Suis tes entraînements, mesure tes progrès, atteins tes objectifs.</p>
        </div>

        <!-- Features -->
        <div class="mt-12 grid w-full max-w-lg animate-slide-up gap-4" style="animation-delay: 0.1s">
            <GlassCard padding="p-5">
                <div class="flex items-center gap-4">
                    <div
                        class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-xl bg-accent-primary/20"
                    >
                        <span class="text-2xl">💪</span>
                    </div>
                    <div>
                        <h3 class="font-semibold text-white">Séances d'entraînement</h3>
                        <p class="text-sm text-white/60">Enregistre chaque exercice, série et répétition</p>
                    </div>
                </div>
            </GlassCard>

            <GlassCard padding="p-5">
                <div class="flex items-center gap-4">
                    <div
                        class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-xl bg-accent-success/20"
                    >
                        <span class="text-2xl">📊</span>
                    </div>
                    <div>
                        <h3 class="font-semibold text-white">Suivi de progression</h3>
                        <p class="text-sm text-white/60">Visualise ton évolution au fil du temps</p>
                    </div>
                </div>
            </GlassCard>

            <GlassCard padding="p-5">
                <div class="flex items-center gap-4">
                    <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-xl bg-accent-info/20">
                        <span class="text-2xl">⚖️</span>
                    </div>
                    <div>
                        <h3 class="font-semibold text-white">Mesures corporelles</h3>
                        <p class="text-sm text-white/60">Suis ton poids et tes mensurations</p>
                    </div>
                </div>
            </GlassCard>
        </div>

        <!-- CTA Buttons -->
        <div class="mt-12 flex w-full max-w-lg animate-slide-up flex-col gap-3" style="animation-delay: 0.2s">
            <Link v-if="canLogin" :href="route('login')">
                <GlassButton variant="primary" size="lg" class="w-full"> Se connecter </GlassButton>
            </Link>

            <Link v-if="canRegister" :href="route('register')">
                <GlassButton size="lg" class="w-full"> Créer un compte </GlassButton>
            </Link>
        </div>

        <!-- Footer -->
        <div class="mt-12 animate-fade-in text-center text-sm text-white/40" style="animation-delay: 0.3s">
            <p>Conçu pour les passionnés de fitness 🏋️</p>
        </div>
    </div>
</template>

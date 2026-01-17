<script setup>
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import LiquidBackground from '@/Components/UI/LiquidBackground.vue'
import { Head, Link } from '@inertiajs/vue3'

defineProps({
    canLogin: Boolean,
    canRegister: Boolean,
})
</script>

<template>
    <Head title="Bienvenue" />

    <div
        class="relative flex min-h-screen flex-col items-center justify-center px-6 py-12"
        :style="{
            paddingTop: 'calc(3rem + var(--safe-area-top))',
            paddingBottom: 'calc(3rem + var(--safe-area-bottom))',
        }"
    >
        <!-- Liquid Background -->
        <LiquidBackground variant="default" />

        <!-- Hero -->
        <div class="relative z-10 max-w-lg animate-fade-in text-center">
            <h1 class="text-gradient font-display text-6xl font-black uppercase italic tracking-tighter">GymTracker</h1>
            <p class="mt-4 text-lg font-medium text-text-muted">
                Suis tes entraînements, mesure tes progrès, atteins tes objectifs.
            </p>
        </div>

        <!-- Features -->
        <div class="relative z-10 mt-12 grid w-full max-w-lg animate-slide-up gap-4" style="animation-delay: 0.1s">
            <GlassCard padding="p-5" :hover="true">
                <div class="flex items-center gap-4">
                    <div
                        class="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-2xl bg-electric-orange/10"
                    >
                        <span class="material-symbols-outlined text-3xl text-electric-orange">fitness_center</span>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-text-main">Séances d'entraînement</h3>
                        <p class="text-sm text-text-muted">Enregistre chaque exercice, série et répétition</p>
                    </div>
                </div>
            </GlassCard>

            <GlassCard padding="p-5" :hover="true">
                <div class="flex items-center gap-4">
                    <div
                        class="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-2xl bg-vivid-violet/10"
                    >
                        <span class="material-symbols-outlined text-3xl text-vivid-violet">monitoring</span>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-text-main">Suivi de progression</h3>
                        <p class="text-sm text-text-muted">Visualise ton évolution au fil du temps</p>
                    </div>
                </div>
            </GlassCard>

            <GlassCard padding="p-5" :hover="true">
                <div class="flex items-center gap-4">
                    <div class="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-2xl bg-cyan-pure/10">
                        <span class="material-symbols-outlined text-3xl text-cyan-pure">scale</span>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-text-main">Mesures corporelles</h3>
                        <p class="text-sm text-text-muted">Suis ton poids et tes mensurations</p>
                    </div>
                </div>
            </GlassCard>
        </div>

        <!-- CTA Buttons -->
        <div
            class="relative z-10 mt-12 flex w-full max-w-lg animate-slide-up flex-col gap-4"
            style="animation-delay: 0.2s"
        >
            <Link v-if="canLogin" :href="route('login')">
                <GlassButton variant="primary" size="lg" class="w-full">
                    <span class="material-symbols-outlined mr-2">login</span>
                    Se connecter
                </GlassButton>
            </Link>

            <Link v-if="canRegister" :href="route('register')">
                <GlassButton variant="gradient-border" size="lg" class="w-full">
                    <span class="material-symbols-outlined mr-2">person_add</span>
                    Créer un compte
                </GlassButton>
            </Link>
        </div>

        <!-- Footer -->
        <div
            class="relative z-10 mt-12 animate-fade-in text-center text-sm text-text-muted"
            style="animation-delay: 0.3s"
        >
            <p>Conçu pour les passionnés de fitness 🏋️</p>
        </div>
    </div>
</template>

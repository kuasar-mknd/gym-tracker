<script setup>
import { Link } from '@inertiajs/vue3'
import LiquidBackground from '@/Components/UI/LiquidBackground.vue'
</script>

<template>
    <div
        class="relative flex min-h-[100dvh] min-h-screen flex-col items-center justify-center px-6 py-12"
        :style="{
            paddingTop: 'calc(3rem + var(--safe-area-top))',
            paddingBottom: 'calc(3rem + var(--safe-area-bottom))',
        }"
    >
        <!-- Liquid Background -->
        <LiquidBackground variant="default" />

        <!-- Logo -->
        <div class="relative z-10 mb-8 animate-fade-in">
            <Link href="/">
                <span class="text-gradient font-display text-4xl font-black uppercase italic tracking-tight">
                    GymTracker
                </span>
            </Link>
        </div>

        <!-- Glass Card -->
        <div class="relative z-10 w-full max-w-md animate-slide-up">
            <div class="glass-panel-light rounded-3xl p-8 shadow-glass-card">
                <slot />
            </div>
        </div>

        <!-- Footer links -->
        <div class="relative z-10 mt-8 animate-fade-in text-center text-sm text-text-muted">
            <slot name="footer" />
        </div>
    </div>
</template>

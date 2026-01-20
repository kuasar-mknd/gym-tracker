<script setup>
import { Link } from '@inertiajs/vue3'
import LiquidBackground from '@/Components/UI/LiquidBackground.vue'
</script>

<template>
    <div
        class="relative flex min-h-dvh min-h-screen flex-col items-center justify-center px-6 py-12"
        :style="{
            paddingTop: 'calc(3rem + var(--safe-area-top))',
            paddingBottom: 'calc(3rem + var(--safe-area-bottom))',
        }"
    >
        <!-- Liquid Background -->
        <LiquidBackground variant="default" />

        <!-- Logo -->
        <div class="animate-fade-in relative z-10 mb-8">
            <Link href="/">
                <span class="text-gradient font-display text-4xl font-black tracking-tight uppercase italic">
                    GymTracker
                </span>
            </Link>
        </div>

        <!-- Glass Card -->
        <div class="animate-slide-up relative z-10 w-full max-w-md">
            <div class="glass-panel-light shadow-glass-card rounded-3xl p-8">
                <slot />
            </div>
        </div>

        <!-- Footer links -->
        <div class="animate-fade-in text-text-muted relative z-10 mt-8 text-center text-sm">
            <slot name="footer" />
        </div>
    </div>
</template>

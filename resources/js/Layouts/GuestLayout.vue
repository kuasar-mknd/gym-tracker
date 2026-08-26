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
        <main id="main-content" class="animate-slide-up relative z-10 w-full max-w-md">
            <div
                class="border-surface-card/20 bg-surface-card/10 hover:border-surface-card/30 hover:bg-surface-card/15 rounded-3xl border p-8 shadow-2xl backdrop-blur-md transition-all duration-300"
            >
                <slot />
            </div>
        </main>

        <!-- Footer links -->
        <div class="animate-fade-in text-text-muted relative z-10 mt-8 text-center text-sm">
            <slot name="footer" />
        </div>
    </div>
</template>

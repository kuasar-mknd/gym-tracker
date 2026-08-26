<script setup>
defineProps({
    variant: {
        type: String,
        default: 'default', // default | cyan-magenta | subtle
    },
})
</script>

<template>
    <div class="pointer-events-none fixed inset-0 z-0 overflow-hidden">
        <!-- Default: Orange / Violet -->
        <template v-if="variant === 'default'">
            <div class="liquid-bg-1"></div>
            <div class="liquid-bg-2"></div>
        </template>

        <!-- Cyan / Magenta for analytics -->
        <template v-else-if="variant === 'cyan-magenta'">
            <div
                class="animate-pulse-glow absolute top-[-10%] left-[-10%] h-[600px] w-[600px] blur-[100px] filter"
                style="
                    background: radial-gradient(
                        circle,
                        rgb(from var(--color-accent-info) r g b / 0.2) 0%,
                        transparent 70%
                    );
                    animation-duration: 4s;
                "
            ></div>
            <div
                class="animate-pulse-glow absolute right-[-10%] bottom-[10%] h-[600px] w-[600px] blur-[100px] filter"
                style="
                    background: radial-gradient(
                        circle,
                        rgb(from var(--color-category-core) r g b / 0.15) 0%,
                        transparent 70%
                    );
                    animation-duration: 6s;
                "
            ></div>
        </template>

        <!-- Subtle for content-heavy pages -->
        <template v-else-if="variant === 'subtle'">
            <div
                class="absolute top-[-5%] right-[-5%] h-[400px] w-[400px] blur-[120px] filter"
                style="
                    background: radial-gradient(
                        circle,
                        rgb(from var(--color-accent-info) r g b / 0.1) 0%,
                        transparent 70%
                    );
                "
            ></div>
            <div
                class="absolute bottom-[20%] left-[-5%] h-[300px] w-[300px] blur-[100px] filter"
                style="
                    background: radial-gradient(
                        circle,
                        rgb(from var(--color-accent-tertiary) r g b / 0.1) 0%,
                        transparent 70%
                    );
                "
            ></div>
        </template>
    </div>
</template>

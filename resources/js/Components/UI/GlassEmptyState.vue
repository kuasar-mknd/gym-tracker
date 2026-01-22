<script setup>
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'

defineProps({
    title: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        default: '',
    },
    icon: {
        type: String, // Emoji or Material Symbol name
        default: '',
    },
    actionLabel: {
        type: String,
        default: '',
    },
    actionId: {
        type: String,
        default: 'empty-state-action',
    },
    color: {
        type: String,
        default: 'orange', // orange, violet, pink, cyan
        validator: (value) => ['orange', 'violet', 'pink', 'cyan', 'green'].includes(value),
    },
})

defineEmits(['action'])

const glowColors = {
    orange: 'bg-electric-orange',
    violet: 'bg-vivid-violet',
    pink: 'bg-hot-pink',
    cyan: 'bg-cyan-pure',
    green: 'bg-neon-green',
}

const shadowColors = {
    orange: 'shadow-glow-orange',
    violet: 'shadow-glow-violet',
    pink: 'shadow-glow-pink',
    cyan: 'shadow-glow-cyan',
    green: 'shadow-neon',
}
</script>

<template>
    <GlassCard class="relative overflow-hidden p-8 text-center" variant="default">
        <!-- Liquid Glow Background behind Icon -->
        <div
            class="absolute top-1/2 left-1/2 h-32 w-32 -translate-x-1/2 -translate-y-1/2 rounded-full opacity-20 blur-3xl"
            :class="glowColors[color]"
        ></div>

        <div class="relative z-10 flex flex-col items-center">
            <!-- Icon Wrapper -->
            <div
                class="mb-4 flex h-20 w-20 items-center justify-center rounded-3xl border border-white/50 bg-white/30 shadow-lg backdrop-blur-md dark:border-slate-700/50 dark:bg-slate-800/30"
            >
                <span v-if="icon && icon.length <= 2" class="text-5xl drop-shadow-sm">{{ icon }}</span>
                <!-- Emoji -->
                <span
                    v-else-if="icon"
                    class="material-symbols-outlined text-4xl"
                    :class="`text-${color === 'orange' ? 'electric-orange' : color === 'violet' ? 'vivid-violet' : color === 'pink' ? 'hot-pink' : color === 'green' ? 'neon-green' : 'cyan-pure'}`"
                >
                    {{ icon }}
                </span>
                <slot name="icon" v-else />
            </div>

            <!-- Content -->
            <h3 class="font-display text-text-main mb-2 text-xl font-black uppercase italic dark:text-white">
                {{ title }}
            </h3>

            <p v-if="description" class="text-text-muted mb-6 max-w-xs text-sm font-medium">
                {{ description }}
            </p>

            <!-- Action -->
            <div v-if="actionLabel || $slots.action">
                <slot name="action">
                    <GlassButton variant="primary" @click="$emit('action')" :data-testid="actionId">
                        {{ actionLabel }}
                    </GlassButton>
                </slot>
            </div>
        </div>
    </GlassCard>
</template>

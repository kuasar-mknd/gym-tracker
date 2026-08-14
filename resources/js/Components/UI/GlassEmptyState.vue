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

/*
 * Les classes sont écrites en toutes lettres, comme celles du halo juste
 * au-dessus.
 *
 * La couleur de l'icône était assemblée par littéral de gabarit —
 * `text-${...}` — et le scanner de Tailwind ne lit que des chaînes littérales
 * complètes : une classe fabriquée à l'exécution n'apparaît jamais dans le CSS
 * généré. La couleur n'était donc pas appliquée, quelle que soit la valeur de
 * `color`. Rien n'échouait ; elle était simplement absente (#1386).
 */
const iconColors = {
    orange: 'text-electric-orange',
    violet: 'text-vivid-violet',
    pink: 'text-hot-pink',
    cyan: 'text-cyan-pure',
    green: 'text-neon-green',
}

/**
 * Un nom de ligature material-symbols, par opposition à un emoji.
 *
 * La distinction se faisait sur `icon.length <= 2`, qui compte les unités
 * UTF-16 et non les caractères perçus : 🏋️ en vaut 3, 🏋️‍♂️ en vaut 6, 🇫🇷 en
 * vaut 4. Tous partaient donc dans la branche icône et se rendaient comme un
 * nom de ligature inexistant — l'utilisateur voyait le texte brut ou rien.
 *
 * Compter des points de code ou des graphèmes réglerait ce cas-ci, mais
 * répondrait toujours à la mauvaise question. Ce qui distingue les deux, ce
 * n'est pas une longueur : un nom de ligature s'écrit en ASCII minuscule avec
 * des tirets bas, et aucun emoji ne peut lui ressembler.
 */
const isLigatureName = (icon) => /^[a-z0-9_]+$/.test(icon)
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
                <!-- Emoji -->
                <span v-if="icon && !isLigatureName(icon)" class="text-5xl drop-shadow-sm">{{ icon }}</span>
                <span
                    v-else-if="icon"
                    class="material-symbols-outlined text-4xl"
                    :class="iconColors[color]"
                    aria-hidden="true"
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

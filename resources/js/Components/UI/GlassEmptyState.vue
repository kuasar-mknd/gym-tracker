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
    /**
     * `carte` occupe une page ; `ligne` tient dans une carte déjà titrée.
     *
     * Dix-sept écrans écrivaient leur propre « Aucun… » parce que la carte
     * complète était trop lourde à l'intérieur d'un graphique (#1791).
     */
    taille: {
        type: String,
        default: 'carte',
        validator: (valeur) => ['carte', 'ligne'].includes(valeur),
    },
})

defineEmits(['action'])

const glowColors = {
    orange: 'bg-accent-primary',
    violet: 'bg-accent-tertiary',
    pink: 'bg-accent-secondary',
    cyan: 'bg-accent-info',
    green: 'bg-accent-state',
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
    orange: 'text-accent-primary-deep',
    violet: 'text-accent-tertiary-deep',
    pink: 'text-accent-secondary-deep',
    cyan: 'text-accent-info-deep',
    green: 'text-accent-state-deep',
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
    <component
        :is="taille === 'carte' ? GlassCard : 'div'"
        :class="
            taille === 'carte'
                ? 'relative overflow-hidden p-8 text-center'
                : 'flex h-full flex-col items-center justify-center py-6 text-center'
        "
        :variant="taille === 'carte' ? 'default' : undefined"
    >
        <!-- Liquid Glow Background behind Icon -->
        <div
            v-if="taille === 'carte'"
            class="absolute top-1/2 left-1/2 h-32 w-32 -translate-x-1/2 -translate-y-1/2 rounded-full opacity-20 blur-3xl"
            :class="glowColors[color]"
        ></div>

        <div class="relative z-10 flex flex-col items-center">
            <!-- Icon Wrapper -->
            <div
                :class="
                    taille === 'carte'
                        ? 'border-surface-card/50 bg-surface-card/30 mb-4 flex h-20 w-20 items-center justify-center rounded-3xl border shadow-lg backdrop-blur-md'
                        : 'mb-2 flex items-center justify-center'
                "
            >
                <!-- Emoji -->
                <span
                    v-if="icon && !isLigatureName(icon)"
                    :class="taille === 'carte' ? 'text-5xl drop-shadow-sm' : 'text-3xl'"
                    >{{ icon }}</span
                >
                <span
                    v-else-if="icon"
                    class="material-symbols-outlined"
                    :class="[iconColors[color], taille === 'carte' ? 'text-4xl' : 'text-3xl']"
                    aria-hidden="true"
                >
                    {{ icon }}
                </span>
                <slot name="icon" v-else />
            </div>

            <!-- Content -->
            <h3 v-if="taille === 'carte'" class="titre-carte mb-2">
                {{ title }}
            </h3>
            <p v-else class="text-text-muted text-sm font-medium">{{ title }}</p>

            <p
                v-if="description"
                class="text-text-muted max-w-xs text-sm font-medium"
                :class="taille === 'carte' ? 'mb-6' : 'mt-1 text-xs'"
            >
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
    </component>
</template>

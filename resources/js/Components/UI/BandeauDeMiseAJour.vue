<script setup>
/**
 * Le bandeau qui propose de recharger quand une nouvelle version est prête (#1967).
 *
 * La mise à jour ne recharge plus la page d'elle-même : elle attend la
 * prochaine navigation, ou ce geste. Le bandeau reste sous les modales, pour ne
 * pas couvrir une saisie, et se range d'un toucher : la nouvelle version
 * s'ouvrira de toute façon à la page suivante.
 */
import { ref } from 'vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassIconButton from '@/Components/UI/GlassIconButton.vue'
import { nouvelleVersionPrete, rechargerMaintenant } from '@/Utils/miseAJourDuWorker'

const range = ref(false)
</script>

<template>
    <div
        v-if="nouvelleVersionPrete && !range"
        class="z-flottant fixed top-20 right-4 left-4 sm:right-6 sm:left-auto sm:w-80"
        role="status"
        aria-live="polite"
        dusk="bandeau-mise-a-jour"
    >
        <div class="glass-panel-light flex items-center gap-3 rounded-2xl p-4 shadow-lg backdrop-blur-xl">
            <p class="text-text-main flex-1 text-sm font-bold">
                Nouvelle version prête. Elle s'ouvrira à la prochaine page.
            </p>
            <GlassButton
                variant="primary"
                size="sm"
                icon="refresh"
                dusk="recharger-la-nouvelle-version"
                @click="rechargerMaintenant()"
            >
                Recharger
            </GlassButton>
            <GlassIconButton icon="close" label="Plus tard" @click="range = true" />
        </div>
    </div>
</template>

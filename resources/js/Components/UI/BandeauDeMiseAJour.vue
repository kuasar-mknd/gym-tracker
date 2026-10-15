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
import { useEtatDuReseau } from '@/composables/useEtatDuReseau'

const range = ref(false)

/*
 * Recharger sans réseau remplaçait la séance ouverte par la page « hors
 * ligne » du worker, minuteurs compris (#2048). « Recharger » attend donc que
 * le serveur réponde ; la nouvelle version s'ouvrira de toute façon à la
 * prochaine page.
 */
const { joignable } = useEtatDuReseau()
</script>

<template>
    <div
        v-if="nouvelleVersionPrete && !range"
        class="z-flottant top-sure-20 right-sure-4 left-sure-4 sm:right-sure-6 fixed sm:left-auto sm:w-80"
        role="status"
        aria-live="polite"
        dusk="bandeau-mise-a-jour"
    >
        <div class="glass-panel-light flex items-center gap-3 rounded-2xl p-4 shadow-lg backdrop-blur-xl">
            <div class="flex-1 space-y-1">
                <p class="text-text-main text-sm font-bold">
                    Nouvelle version prête. Elle s'ouvrira à la prochaine page.
                </p>
                <p v-if="!joignable" id="recharger-sans-reseau" class="text-text-muted text-xs font-medium">
                    Il faut le réseau pour recharger.
                </p>
            </div>
            <GlassButton
                variant="primary"
                size="sm"
                icon="refresh"
                dusk="recharger-la-nouvelle-version"
                :disabled="!joignable"
                :aria-describedby="joignable ? undefined : 'recharger-sans-reseau'"
                @click="rechargerMaintenant()"
            >
                Recharger
            </GlassButton>
            <GlassIconButton icon="close" label="Plus tard" @click="range = true" />
        </div>
    </div>
</template>

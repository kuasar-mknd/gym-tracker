<script setup>
/**
 * Le bandeau qui propose d'installer l'application.
 *
 * Elle est une PWA complète depuis longtemps — worker, manifeste, hors-ligne —
 * et rien ne le disait (#1805). Deux formes, parce que les deux plateformes ne
 * se ressemblent pas : Android sait installer tout seul, iPhone demande un
 * geste que personne ne devine.
 */
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import GlassIconButton from '@/Components/UI/GlassIconButton.vue'
import { useInvitationDInstallation } from '@/composables/useInvitationDInstallation'

const { visible, forme, installer, refuser } = useInvitationDInstallation()
</script>

<template>
    <GlassCard v-if="visible" class="animate-slide-up flex items-start gap-4" dusk="invitation-installation">
        <div
            class="bg-accent-primary/20 text-accent-primary-deep flex size-12 shrink-0 items-center justify-center rounded-2xl"
        >
            <GlassIcon name="install_mobile" size="lg" />
        </div>

        <div class="min-w-0 flex-1 space-y-2">
            <h3 class="titre-carte">Garde-la sous la main</h3>

            <p v-if="forme === 'native'" class="text-text-muted text-sm font-medium">
                Installée, l'application s'ouvre en un geste et fonctionne sans réseau.
            </p>
            <p v-else class="text-text-muted text-sm font-medium">
                Appuie sur <span class="text-text-main font-bold">Partager</span>, puis sur
                <span class="text-text-main font-bold">« Sur l'écran d'accueil »</span>. Elle s'ouvrira en un geste et
                fonctionnera sans réseau.
            </p>

            <GlassButton
                v-if="forme === 'native'"
                variant="primary"
                size="sm"
                icon="install_mobile"
                dusk="installer-application"
                @click="installer"
            >
                Installer
            </GlassButton>
        </div>

        <GlassIconButton icon="close" label="Ne plus proposer l'installation" compact @click="refuser" />
    </GlassCard>
</template>

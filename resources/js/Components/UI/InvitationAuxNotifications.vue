<script setup>
/**
 * La carte qui propose d'activer les notifications, au retour d'une séance.
 *
 * Rien ne le proposait hors du profil (#1848). Elle ne parle que des records,
 * les seuls envois que « Activer » allume ; les rappels d'entraînement restent
 * dans le profil. Même forme que l'invitation d'installation, qui passe
 * d'abord quand les deux auraient à se montrer.
 */
import { usePage } from '@inertiajs/vue3'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import GlassIconButton from '@/Components/UI/GlassIconButton.vue'
import { useInvitationAuxNotifications } from '@/composables/useInvitationAuxNotifications'

const props = defineProps({
    /** L'invitation d'installation est à l'écran : elle passe d'abord. */
    uneAutreInvitationPasseAvant: {
        type: Boolean,
        default: false,
    },
})

const page = usePage()

const { visible, activee, enCours, etapeEnCours, erreur, activer, refuser, fermer } = useInvitationAuxNotifications({
    vapidPublicKey: page.props.vapidPublicKey,
    utilisateurId: page.props.auth?.user?.id,
    uneAutreInvitationPasseAvant: () => props.uneAutreInvitationPasseAvant,
})
</script>

<template>
    <GlassCard v-if="visible" class="animate-slide-up flex items-start gap-4" dusk="invitation-notifications">
        <div
            class="bg-accent-primary/20 text-accent-primary-deep flex size-12 shrink-0 items-center justify-center rounded-2xl"
        >
            <GlassIcon name="notifications" size="lg" />
        </div>

        <div v-if="activee" class="min-w-0 flex-1 space-y-2" role="status">
            <h3 class="titre-carte">C'est activé</h3>
            <p class="text-text-muted text-sm font-medium">
                Tes prochains records s'afficheront sur cet appareil. Les rappels d'entraînement se règlent dans ton
                profil.
            </p>
        </div>

        <div v-else class="min-w-0 flex-1 space-y-3">
            <h3 class="titre-carte">Ne rate plus un record</h3>
            <p class="text-text-muted text-sm font-medium">
                Active les notifications de tes records sur cet appareil : tu sauras dès que tu en bats un, même
                l'application fermée.
            </p>

            <p v-if="erreur" class="text-accent-danger-deep text-sm font-bold" role="alert">{{ erreur }}</p>

            <div class="flex flex-wrap gap-2">
                <GlassButton
                    variant="primary"
                    size="sm"
                    dusk="activer-notifications"
                    :loading="enCours"
                    @click="activer"
                >
                    {{ enCours && etapeEnCours ? `${etapeEnCours}…` : 'Activer' }}
                </GlassButton>
                <GlassButton
                    variant="secondary"
                    size="sm"
                    dusk="refuser-notifications"
                    :disabled="enCours"
                    @click="refuser"
                >
                    Non merci
                </GlassButton>
            </div>
        </div>

        <GlassIconButton v-if="activee" icon="close" label="Fermer" compact @click="fermer" />
    </GlassCard>
</template>

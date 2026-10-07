<script setup>
import { onMounted, ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import LiquidBackground from '@/Components/UI/LiquidBackground.vue'
import BandeauDeMiseAJour from '@/Components/UI/BandeauDeMiseAJour.vue'
import { marquerLAbonnementARetransmettre } from '@/composables/useAbonnementPush'
import { messageDesEcrituresGardees, reprendreLesEcrituresGardees } from '@/Utils/ecrituresGardees'

const page = usePage()

/**
 * Les écritures hors ligne que la dernière déconnexion a laissées en file pour
 * son compte : l'écran qui la suit le dit, une fois (#1964).
 */
const ecrituresGardees = ref(0)

onMounted(() => {
    ecrituresGardees.value = reprendreLesEcrituresGardees()

    /*
     * Une page d'invité dit que cet appareil n'a plus de session. Elle a pu être
     * fermée par un changement de mot de passe, qui retire aussi tous les
     * abonnements push du compte : le mémo de l'appareil ne prouve plus que le
     * serveur tient le sien, et la connexion suivante au même compte le
     * retransmet. La vérification de l'adresse et la confirmation du mot de passe
     * prennent ce gabarit sous une session ouverte : rien à marquer.
     */
    if (!page.props.auth?.user) {
        marquerLAbonnementARetransmettre()
    }
})
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

        <BandeauDeMiseAJour />

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
                class="border-surface-card/20 bg-surface-card/10 hover:border-surface-card/30 hover:bg-surface-card/15 rounded-3xl border p-8 shadow-2xl backdrop-blur-md transition duration-300"
            >
                <p
                    v-if="ecrituresGardees > 0"
                    role="status"
                    class="info-fill border-accent-info mb-4 rounded-xl border p-3 text-sm font-medium"
                    dusk="ecritures-gardees"
                >
                    {{ messageDesEcrituresGardees(ecrituresGardees) }}
                </p>
                <slot />
            </div>
        </main>

        <!-- Footer links -->
        <div class="animate-fade-in text-text-muted relative z-10 mt-8 text-center text-sm">
            <slot name="footer" />
        </div>
    </div>
</template>

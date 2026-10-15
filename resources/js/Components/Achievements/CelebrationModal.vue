<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import GlassButton from '@/Components/UI/GlassButton.vue'

const dialog = ref(null)

const page = usePage()

/**
 * Les badges que la personne a fermés pendant ce montage.
 *
 * Le badge affiché se déduit de la page et de cette liste ; ce n'est plus une
 * copie que deux écrivains se disputent (#2052). L'ancienne copie était posée
 * par un observateur de `latest_achievement` et effacée par le `onSuccess` de
 * la lecture. Or Inertia pose la page, Vue rejoue ses observateurs, et SEULEMENT
 * ensuite appelle `onSuccess` : quand la réponse de la lecture portait le badge
 * suivant, l'observateur l'affichait, puis le rappel l'effaçait, et la modale
 * restait ouverte sur un badge nul (« Cannot read properties of null (reading
 * 'icon') »). Ici, rien n'est effacé après coup : un badge fermé reste fermé
 * même si une page le porte encore, et le suivant s'affiche dès que le serveur
 * le rend.
 */
const badgesFermes = reactive(new Set())

/**
 * Le badge à célébrer : le plus récent des non lus, s'il n'a pas déjà été fermé ici.
 *
 * Son icône, son nom et sa description viennent du badge lui-même, que le
 * serveur joint à la notification ; un badge supprimé depuis n'a que le message
 * de la notification, qui le nomme.
 */
const badge = computed(() => {
    if (page.props.is_testing) {
        return null
    }

    const dernier = page.props.auth?.user?.latest_achievement

    return dernier?.data && !badgesFermes.has(dernier.id) ? dernier : null
})

/**
 * This one deliberately does not route through Components/UI/Modal: its
 * overlay and card are a different design on purpose — a near-black backdrop,
 * a dark glass panel, a bounce-in — and Modal's own chrome would stack a
 * second background and border on top of them.
 *
 * What it does need is the part Modal gets for free. It had the ARIA
 * attributes of a dialog without being one: nothing kept Tab inside it,
 * Escape did nothing, and the page behind stayed reachable — on an overlay
 * that covers the whole screen. showModal() supplies all of that natively.
 */
const ouvrirOuFermer = (ouvert) => {
    if (ouvert && !dialog.value?.open) {
        dialog.value?.showModal()
    } else if (!ouvert && dialog.value?.open) {
        dialog.value.close()
    }
}

/**
 * `post` : le panneau du dialogue est rendu par un `v-if` sur le même badge,
 * l'ouverture attend donc ce rendu, sans quoi le dialogue s'ouvre vide. Le
 * montage ouvre de lui-même le badge que la première page porte déjà.
 */
watch(() => badge.value !== null, ouvrirOuFermer, { flush: 'post' })

onMounted(() => ouvrirOuFermer(badge.value !== null))

/**
 * Ferme le badge affiché et le marque lu.
 *
 * Réentrante : fermer le dialogue émet son propre `close`, et Échap le ferme
 * avant qu'on n'arrive ici. Le badge fermé n'étant plus celui qu'on affiche,
 * le second appel ne trouve rien à fermer, et la lecture ne part qu'une fois.
 */
const fermer = () => {
    const badgeFerme = badge.value

    if (badgeFerme === null) {
        return
    }

    badgesFermes.add(badgeFerme.id)

    router.post(
        route('notifications.mark-as-read', { id: badgeFerme.id }),
        {},
        { preserveScroll: true, preserveState: true },
    )
}
</script>

<template>
    <Teleport to="body">
        <dialog
            ref="dialog"
            class="m-0 h-full max-h-full w-full max-w-full bg-transparent p-0 backdrop:bg-transparent"
            aria-labelledby="achievement-title"
            aria-describedby="achievement-name achievement-description"
            @close="fermer"
        >
            <!--
              Centrée dans la zone sûre, et bornée à elle : en paysage, la carte
              défile au lieu de passer sous les barres (#2043). Un écart de
              12 px seulement au-dessus et au-dessous (`py-sure-3`, borne
              `hauteur-sure-6`) : sans encoche, la carte tient encore entière
              sur un écran de 375 px de haut, comme avant.
            -->
            <div v-if="badge" class="z-modale px-sure-4 py-sure-3 fixed inset-0 flex items-center justify-center">
                <!-- Backdrop -->
                <div class="animate-fade-in bg-overlay absolute inset-0 backdrop-blur-sm" @click="fermer"></div>

                <!-- Modal. No role or aria-modal here: the <dialog> above is the
                     dialog, and nesting a second one would announce two. -->
                <div
                    class="animate-bounce-in border-border bg-surface-card hauteur-sure-6 relative w-full max-w-sm overflow-y-auto rounded-3xl border p-6 text-center shadow-2xl transition duration-300"
                >
                    <!-- Glow Effect -->
                    <div
                        class="to-accent-secondary/20 from-accent-primary/20 pointer-events-none absolute inset-0 rounded-3xl bg-linear-to-br via-transparent opacity-50"
                    ></div>

                    <div class="relative z-10 flex flex-col items-center">
                        <!-- Confetti/Burst Animation Background (CSS only) -->
                        <div class="pointer-events-none absolute top-0 left-0 h-full w-full overflow-hidden">
                            <div
                                class="bg-accent-primary/20 absolute top-1/2 left-1/2 h-64 w-64 -translate-x-1/2 -translate-y-1/2 animate-pulse rounded-full blur-[60px]"
                            ></div>
                        </div>

                        <!-- Icon -->
                        <div class="relative mb-4" aria-hidden="true">
                            <div
                                class="animate-wobble border-border bg-surface-sunken flex h-24 w-24 items-center justify-center rounded-3xl border text-6xl shadow-lg backdrop-blur-md"
                            >
                                {{ badge.icon || '🏆' }}
                            </div>
                        </div>

                        <!-- Text -->
                        <h2 class="titre-carte mb-1" id="achievement-title">Badge débloqué !</h2>
                        <h3 v-if="badge.name" class="text-accent-primary-deep titre-carte mb-3" id="achievement-name">
                            {{ badge.name }}
                        </h3>
                        <p class="text-text-muted mb-6 text-sm" id="achievement-description">
                            {{ badge.description || badge.data.message || 'Félicitations pour cet exploit !' }}
                        </p>

                        <!-- Action -->
                        <GlassButton
                            variant="primary"
                            size="lg"
                            class="w-full"
                            dusk="celebration-dismiss"
                            @click="fermer"
                        >
                            Génial ! 🤩
                        </GlassButton>
                    </div>
                </div>
            </div>
        </dialog>
    </Teleport>
</template>

<style scoped>
@keyframes bounce-in {
    0% {
        transform: scale(0.3);
        opacity: 0;
    }
    50% {
        transform: scale(1.05);
    }
    70% {
        transform: scale(0.9);
    }
    100% {
        transform: scale(1);
        opacity: 1;
    }
}
.animate-bounce-in {
    animation: bounce-in 0.6s cubic-bezier(0.21, 1.02, 0.73, 1) forwards;
}

@keyframes wobble {
    0%,
    100% {
        transform: rotate(0deg);
    }
    15% {
        transform: rotate(-5deg);
    }
    30% {
        transform: rotate(3deg);
    }
    45% {
        transform: rotate(-3deg);
    }
    60% {
        transform: rotate(2deg);
    }
    75% {
        transform: rotate(-1deg);
    }
}
.animate-wobble {
    animation: wobble 1s ease-in-out infinite;
    animation-delay: 0.5s;
}

@keyframes fade-in {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}
.animate-fade-in {
    animation: fade-in 0.3s ease-out forwards;
}
</style>

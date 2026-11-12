<script setup>
/**
 * Le message d'une écriture de la séance refusée (`useRapportDeSynchronisation`).
 *
 * Fixe plutôt que dans le flux : la série en cours de saisie peut être
 * n'importe où dans une longue séance, et un message qui sort de l'écran en
 * défilant ne dit rien.
 */
import GlassButton from '@/Components/UI/GlassButton.vue'

defineProps({
    /** Le message, ou rien. */
    message: { type: String, default: null },
    /** Il reste jusqu'à sa fermeture : un bouton la propose. */
    persistant: { type: Boolean, default: false },
    /** La session a expiré : un lien mène à la connexion. */
    reconnexion: { type: Boolean, default: false },
})

const emit = defineEmits(['fermer'])

/** « Fermer » retire le bouton focalisé : le focus passe au contenu principal, sans attendre la transition. */
const fermer = () => {
    const actif = document.activeElement
    const perdu = actif === null || actif === document.body || actif.closest('[dusk="set-edit-error"]') !== null

    emit('fermer')

    if (perdu) {
        document.getElementById('main-content')?.focus({ preventScroll: true })
    }
}
</script>

<template>
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="-translate-y-2 opacity-0"
        leave-active-class="transition duration-150 ease-in"
        leave-to-class="-translate-y-2 opacity-0"
    >
        <div
            v-if="message"
            role="alert"
            dusk="set-edit-error"
            class="border-accent-danger/30 danger-fill z-toast left-sure-3 right-sure-3 top-sure-20 fixed rounded-2xl border px-4 py-3 text-sm font-bold shadow-lg backdrop-blur-md"
        >
            {{ message }}
            <div v-if="persistant" class="mt-2 flex flex-wrap gap-2">
                <GlassButton v-if="reconnexion" :href="route('login')" dusk="set-edit-error-reconnexion">
                    Se reconnecter
                </GlassButton>
                <GlassButton dusk="set-edit-error-fermer" @click="fermer">Fermer</GlassButton>
            </div>
        </div>
    </Transition>
</template>

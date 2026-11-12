<script setup>
/**
 * Le message d'une écriture de la séance refusée (`useRapportDeSynchronisation`).
 *
 * Fixe plutôt que dans le flux : la série en cours de saisie peut être
 * n'importe où dans une longue séance, et un message qui sort de l'écran en
 * défilant ne dit rien.
 */
defineProps({
    /** Le message, ou rien. */
    message: { type: String, default: null },
    /** Il reste jusqu'à sa fermeture : un bouton la propose. */
    persistant: { type: Boolean, default: false },
    /** La session a expiré : un lien mène à la connexion. */
    reconnexion: { type: Boolean, default: false },
})

defineEmits(['fermer'])
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
            class="border-accent-danger/30 bg-accent-danger/95 text-text-on-accent z-toast left-sure-3 right-sure-3 top-sure-20 fixed rounded-2xl border px-4 py-3 text-sm font-bold shadow-lg backdrop-blur-md"
        >
            {{ message }}
            <a
                v-if="reconnexion"
                :href="route('login')"
                dusk="set-edit-error-reconnexion"
                class="ml-1 underline focus-visible:outline-2"
                >Se reconnecter</a
            >
            <button
                v-if="persistant"
                type="button"
                dusk="set-edit-error-fermer"
                class="ml-2 underline focus-visible:outline-2"
                @click="$emit('fermer')"
            >
                Fermer
            </button>
        </div>
    </Transition>
</template>

<script setup>
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import { retransmettreLAbonnementPush } from '@/composables/useAbonnementPush'
import { useForm, usePage } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

const page = usePage()
const passwordInput = ref(null)
const currentPasswordInput = ref(null)

/*
 * L'identifiant du compte connecté, pour les gestionnaires de mots de passe
 * (#PR). Le formulaire change le mot de passe sans demander l'adresse : un
 * gestionnaire rangeait le nouveau mot de passe sans savoir sous quel compte.
 * Chromium demande d'inclure ce que le contexte rend évident, « the username
 * field in a change password form », dans un champ caché ; 1Password, sur un
 * formulaire qui réinitialise un mot de passe, l'identifiant dans un champ
 * `autocomplete="username"` en `display: none`, et `aria-hidden` sur un champ
 * qu'on ne voit pas. Le champ est donc caché (`hidden`) : hors de l'écran,
 * de l'ordre de tabulation et de l'arbre d'accessibilité, il n'a pas besoin
 * de libellé. En `type="text"`, que la validation du navigateur ne juge pas :
 * une adresse ancienne qu'un champ `email` refuserait bloquerait l'envoi sans
 * rien montrer. Il n'est pas envoyé : il n'a pas de nom, et n'est pas dans
 * le formulaire d'Inertia. L'adresse vient de la page (`auth.user`), aucune
 * prop ne l'ajoute.
 */
const identifiantDuCompte = computed(() => page.props.auth?.user?.email ?? '')

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
})

/*
 * Le serveur retire tous les abonnements push du compte quand le mot de passe
 * change, celui de cet appareil compris : il ne sait pas lequel est le sien.
 * Cet appareil garde sa session, et retransmet le sien ; les autres, dont la
 * session est fermée, ne reçoivent plus rien.
 */
const updatePassword = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
            retransmettreLAbonnementPush(page.props.auth?.user?.id)
        },
        onError: () => {
            if (form.errors.password) {
                form.reset('password', 'password_confirmation')
                passwordInput.value?.focus()
            }
            if (form.errors.current_password) {
                form.reset('current_password')
                currentPasswordInput.value?.focus()
            }
        },
    })
}
</script>

<template>
    <GlassCard as="section">
        <header>
            <h2 class="titre-carte">Mot de passe</h2>
            <p class="text-text-muted mt-1 text-sm">
                Utilise un mot de passe long et unique pour sécuriser ton compte.
            </p>
        </header>

        <form @submit.prevent="updatePassword" class="mt-6 space-y-4">
            <input
                type="text"
                autocomplete="username"
                :value="identifiantDuCompte"
                hidden
                aria-hidden="true"
                data-testid="identifiant-du-compte"
            />

            <GlassInput
                v-model="form.current_password"
                ref="currentPasswordInput"
                dusk="current-password-input"
                type="password"
                label="Mot de passe actuel"
                :error="form.errors.current_password"
                autocomplete="current-password"
            />

            <GlassInput
                v-model="form.password"
                ref="passwordInput"
                dusk="new-password-input"
                type="password"
                label="Nouveau mot de passe"
                :error="form.errors.password"
                autocomplete="new-password"
            />

            <GlassInput
                v-model="form.password_confirmation"
                dusk="confirm-password-input"
                type="password"
                label="Confirmer le mot de passe"
                :error="form.errors.password_confirmation"
                autocomplete="new-password"
            />

            <div class="flex items-center gap-4">
                <GlassButton
                    block
                    type="submit"
                    variant="primary"
                    :loading="form.processing"
                    data-testid="update-password-button"
                >
                    Enregistrer
                </GlassButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p v-if="form.recentlySuccessful" class="text-accent-state-deep text-sm">Enregistré ✓</p>
                </Transition>
            </div>
        </form>
    </GlassCard>
</template>

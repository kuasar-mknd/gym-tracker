<script setup>
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import IdentifiantDuCompte from '@/Components/Form/IdentifiantDuCompte.vue'
import CheminVersUnMotDePasse from '@/Components/Form/CheminVersUnMotDePasse.vue'
import { retransmettreLAbonnementPush } from '@/composables/useAbonnementPush'
import { useForm, usePage } from '@inertiajs/vue3'
import { ref } from 'vue'

/*
 * Un compte relié à un fournisseur de connexion peut n'avoir jamais choisi de
 * mot de passe : le formulaire, qui demande le mot de passe actuel, lui dit
 * comment en obtenir un, comme celui de l'adresse (#2104). Les deux props
 * viennent de la page (`ProfileController::edit`).
 */
const props = defineProps({
    /** Le fournisseur relié au compte (« Google »…), ou null pour un compte à mot de passe seul. */
    fournisseurDeConnexion: {
        type: String,
        default: null,
    },
    /** La messagerie écrit-elle l'adresse actuelle telle quelle ? Sinon, aucun lien ne lui part. */
    laMessagerieSaitEcrireSonAdresse: {
        type: Boolean,
        default: true,
    },
})

const page = usePage()
const passwordInput = ref(null)
const currentPasswordInput = ref(null)

/*
 * Le formulaire change le mot de passe sans demander l'adresse : il porte
 * l'identifiant du compte, caché et jamais envoyé, sous lequel un
 * gestionnaire de mots de passe range le nouveau (`IdentifiantDuCompte`, #2076).
 */
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
            <IdentifiantDuCompte data-testid="identifiant-du-compte" />

            <GlassInput
                v-model="form.current_password"
                ref="currentPasswordInput"
                dusk="current-password-input"
                type="password"
                label="Mot de passe actuel"
                :error="form.errors.current_password"
                autocomplete="current-password"
            />

            <!-- Le refus du serveur porte le même chemin sous le champ : l'encadré s'efface devant lui. -->
            <CheminVersUnMotDePasse
                v-if="!form.errors.current_password"
                :fournisseur-de-connexion="props.fournisseurDeConnexion"
                :la-messagerie-sait-ecrire-son-adresse="props.laMessagerieSaitEcrireSonAdresse"
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

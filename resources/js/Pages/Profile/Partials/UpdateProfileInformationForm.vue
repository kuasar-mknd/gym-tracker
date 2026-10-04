<script setup>
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'

const props = defineProps({
    mustVerifyEmail: Boolean,
    status: String,
    /** Le fournisseur relié au compte (« Google »…), ou null pour un compte à mot de passe seul. */
    fournisseurDeConnexion: {
        type: String,
        default: null,
    },
    /**
     * L'adresse actuelle est-elle vérifiée ? Donné par la page
     * (`ProfileController::edit`) : `auth.user` ne le partage pas. Après un
     * changement d'adresse, la page revient avec `false`.
     */
    adresseVerifiee: Boolean,
})

const page = usePage()

/**
 * Lu à chaque rendu plutôt qu'une fois au montage : après un changement
 * d'adresse réussi, c'est la nouvelle adresse qui fait référence, sans quoi le
 * champ du mot de passe resterait affiché pour une adresse déjà enregistrée.
 */
const user = computed(() => page.props.auth.user)

const motDePasseActuel = ref(null)

const form = useForm({
    name: user.value.name,
    email: user.value.email,
    current_password: '',
})

/** Le serveur n'exige le mot de passe actuel que lorsque l'adresse change. */
const adresseChangee = computed(() => form.email !== user.value.email)

/**
 * Le mot de passe ne sert qu'à l'adresse qu'il accompagne : il s'efface quand
 * l'adresse revient à celle du compte, saisie à la main ou enregistrée.
 */
watch(adresseChangee, (changee) => {
    if (!changee) {
        form.current_password = ''
    }
})

const submit = () => {
    form.patch(route('profile.update'), {
        onError: () => {
            if (form.errors.current_password) {
                form.reset('current_password')
                motDePasseActuel.value?.focus()
            }
        },
    })
}
</script>

<template>
    <GlassCard as="section">
        <header>
            <h2 class="titre-carte">Informations du profil</h2>
            <p class="text-text-muted mt-1 text-sm">Modifie tes informations de compte et ton adresse email.</p>
        </header>

        <form @submit.prevent="submit" class="mt-6 space-y-4">
            <GlassInput
                dusk="profile-name-input"
                v-model="form.name"
                type="text"
                label="Nom"
                :error="form.errors.name"
                autocomplete="name"
                required
            />

            <GlassInput
                v-model="form.email"
                type="email"
                label="Email"
                :error="form.errors.email"
                autocomplete="username"
                required
            />

            <template v-if="adresseChangee">
                <GlassInput
                    v-model="form.current_password"
                    ref="motDePasseActuel"
                    dusk="profile-current-password-input"
                    type="password"
                    label="Mot de passe actuel"
                    :error="form.errors.current_password"
                    autocomplete="current-password"
                    required
                />

                <p class="text-text-muted text-sm" data-testid="profile-email-change-notice">
                    Ton mot de passe confirme que c’est bien toi.
                    <template v-if="props.adresseVerifiee">
                        Ton adresse actuelle sera prévenue du changement, et la nouvelle devra être vérifiée.
                    </template>
                    <template v-else>La nouvelle adresse devra être vérifiée.</template>
                </p>

                <div
                    v-if="props.fournisseurDeConnexion && !form.errors.current_password"
                    class="bg-accent-info/10 rounded-xl p-3"
                    data-testid="profile-social-password-help"
                >
                    <p class="text-accent-info-deep text-sm">
                        Ton compte est relié à {{ props.fournisseurDeConnexion }}. Si tu n’as jamais choisi de mot de
                        passe, choisis-en un d’abord : déconnecte-toi, puis « Mot de passe oublié ? » sur la page de
                        connexion. Le lien part à ton adresse actuelle, {{ user.email }}.
                    </p>
                </div>
            </template>

            <div
                v-if="mustVerifyEmail && !props.adresseVerifiee"
                class="bg-accent-warning/20 rounded-xl p-3"
                data-testid="profile-email-unverified"
            >
                <p class="text-accent-warning-deep text-sm">
                    Ton adresse email n'est pas vérifiée.
                    <Link
                        :href="route('verification.send')"
                        method="post"
                        as="button"
                        class="ml-1 underline hover:no-underline"
                    >
                        Renvoyer l'email de vérification
                    </Link>
                </p>

                <p v-if="status === 'verification-link-sent'" class="text-accent-state-deep mt-2 text-sm">
                    Un nouveau lien a été envoyé.
                </p>
            </div>

            <div class="flex items-center gap-4">
                <GlassButton
                    block
                    variant="primary"
                    dusk="save-profile-btn"
                    type="submit"
                    :loading="form.processing"
                    data-testid="save-profile-button"
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

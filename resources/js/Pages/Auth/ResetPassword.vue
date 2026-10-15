<script setup>
/**
 * La page qu'ouvre le lien du courriel « mot de passe oublié ».
 *
 * Le lien ne porte l'adresse du compte que chiffrée : la page la reçoit telle
 * quelle (`adresse`) et la renvoie avec le jeton et le nouveau mot de passe,
 * sans la lire ni l'afficher. Un lien refusé (jeton faux ou périmé, lien d'avant
 * le chiffrement) revient avec l'erreur sous `token`, montrée en tête avec le
 * chemin d'une nouvelle demande.
 */
import InputError from '@/Components/Form/InputError.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'

const props = defineProps({
    token: String,
    adresse: String,
})

const form = useForm({
    token: props.token,
    adresse: props.adresse,
    password: '',
    password_confirmation: '',
})

const submit = () => {
    form.post(route('password.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    })
}
</script>

<template>
    <GuestLayout>
        <Head title="Réinitialiser le mot de passe" />

        <div class="mb-6 text-center">
            <h2 class="titre-section">Nouveau mot de passe</h2>
            <p class="text-text-muted mt-1">Choisis un nouveau mot de passe sécurisé</p>
        </div>

        <div v-if="form.errors.token" class="mb-4" data-testid="lien-refuse">
            <InputError id="lien-refuse" :message="form.errors.token" />
            <Link
                :href="route('password.request')"
                class="text-accent-primary-deep hover:text-accent-tertiary-deep mt-2 inline-block text-sm font-semibold transition-colors"
            >
                Demander un nouveau lien
            </Link>
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <GlassInput
                v-model="form.password"
                type="password"
                label="Nouveau mot de passe"
                placeholder="••••••••"
                :error="form.errors.password"
                autocomplete="new-password"
                required
                autofocus
            />

            <GlassInput
                v-model="form.password_confirmation"
                type="password"
                label="Confirmer le mot de passe"
                placeholder="••••••••"
                :error="form.errors.password_confirmation"
                autocomplete="new-password"
                required
            />

            <GlassButton type="submit" variant="primary" class="w-full" :loading="form.processing">
                Réinitialiser
            </GlassButton>
        </form>
    </GuestLayout>
</template>

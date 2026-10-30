<script setup>
/**
 * La page qu'ouvre le lien du courriel « mot de passe oublié ».
 *
 * Le serveur vérifie le lien avant d'ouvrir le formulaire. Un lien qui vaut
 * donne à la page le jeton et l'adresse chiffrée tels que le lien les porte
 * (`token`, `adresse`), qu'elle renvoie avec le nouveau mot de passe sans les
 * lire, et rien de l'adresse du compte. La personne la saisit sous « Email »,
 * dans un champ vide et modifiable, `autocomplete="username"`, envoyé avec le
 * formulaire (`email`) : un gestionnaire de mots de passe le remplit, et
 * range le nouveau mot de passe sous cet identifiant. Le serveur ne
 * réinitialise que si elle désigne le compte du lien ; sinon, il répond comme
 * à un jeton faux. Une fois montée, la page vide la page initiale du document
 * (`viderLaPageInitialeDuDocument()`), que le chiffrement de l'historique ne
 * couvre pas et qu'Inertia garderait, jeton et valeur chiffrée compris, tant
 * que l'onglet ne recharge pas.
 *
 * Un lien refusé (jeton faux ou périmé, lien d'avant le chiffrement) le dit
 * dès l'ouverture (`lienRefuse`), avec le chemin d'une nouvelle demande, sans
 * formulaire. Un envoi refusé plus tard revient avec l'erreur sous `token`,
 * montrée au même endroit.
 */
import { computed, onMounted } from 'vue'
import InputError from '@/Components/Form/InputError.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GuestLayout from '@/Layouts/GuestLayout.vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import { viderLaPageInitialeDuDocument } from '@/Utils/historiqueDuCompte'
import { NOUVEAU_MOT_DE_PASSE_NON_CONFIRME, direSansReseau } from '@/Utils/visitesHorsLigne'

const props = defineProps({
    token: String,
    adresse: String,
    lienRefuse: String,
})

const form = useForm({
    token: props.token,
    adresse: props.adresse,
    email: '',
    password: '',
    password_confirmation: '',
})

const refus = computed(() => props.lienRefuse || form.errors.token)

onMounted(() => viderLaPageInitialeDuDocument(document, 'Auth/ResetPassword'))

const submit = () => {
    form.post(route('password.store'), {
        onNetworkError: direSansReseau(NOUVEAU_MOT_DE_PASSE_NON_CONFIRME),
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

        <div v-if="refus" class="mb-4" data-testid="lien-refuse">
            <InputError id="lien-refuse" :message="refus" />
            <Link
                :href="route('password.request')"
                class="text-accent-primary-deep hover:text-accent-tertiary-deep mt-2 inline-block text-sm font-semibold transition-colors"
            >
                Demander un nouveau lien
            </Link>
        </div>

        <!--
            Comme « mot de passe oublié » (#2104) : l'adresse part telle qu'elle
            est tapée, dans un champ texte au clavier d'adresse, que le navigateur
            ne juge ni ne convertit en Punycode, et le serveur juge chaque champ,
            vide compris (`novalidate`) ; ses refus s'affichent dessous. Un champ
            `type="email"` refusait ou transformait une adresse que le lien a
            reçue et sous laquelle le serveur retrouve le compte.
        -->
        <form v-if="!lienRefuse" novalidate @submit.prevent="submit" class="space-y-4">
            <GlassInput
                v-model="form.email"
                type="text"
                inputmode="email"
                autocapitalize="none"
                spellcheck="false"
                name="email"
                label="Email"
                placeholder="ton@email.com"
                :error="form.errors.email"
                autocomplete="username"
                required
                autofocus
            />

            <GlassInput
                v-model="form.password"
                type="password"
                label="Nouveau mot de passe"
                placeholder="••••••••"
                :error="form.errors.password"
                autocomplete="new-password"
                required
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

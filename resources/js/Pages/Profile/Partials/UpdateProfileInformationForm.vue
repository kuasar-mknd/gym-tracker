<script setup>
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import { Link, useForm, usePage } from '@inertiajs/vue3'

defineProps({
    mustVerifyEmail: Boolean,
    status: String,
})

const user = usePage().props.auth.user

const form = useForm({
    name: user.name,
    email: user.email,
})

const submit = () => {
    form.patch(route('profile.update'))
}
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-semibold text-text-main">Informations du profil</h2>
            <p class="mt-1 text-sm text-text-muted">Modifie tes informations de compte et ton adresse email.</p>
        </header>

        <form @submit.prevent="submit" class="mt-6 space-y-4">
            <GlassInput
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

            <div v-if="mustVerifyEmail && user.email_verified_at === null" class="rounded-xl bg-accent-warning/20 p-3">
                <p class="text-sm text-accent-warning">
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

                <p v-if="status === 'verification-link-sent'" class="mt-2 text-sm text-accent-success">
                    Un nouveau lien a été envoyé.
                </p>
            </div>

            <div class="flex items-center gap-4">
                <GlassButton type="submit" :loading="form.processing"> Enregistrer </GlassButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p v-if="form.recentlySuccessful" class="text-sm text-accent-success">Enregistré ✓</p>
                </Transition>
            </div>
        </form>
    </section>
</template>

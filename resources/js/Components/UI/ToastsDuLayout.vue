<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import GlassIconButton from '@/Components/UI/GlassIconButton.vue'
import { EVENEMENT_SANS_RESEAU } from '@/Utils/visitesHorsLigne'

/**
 * Les toasts d'un layout : le succès et l'erreur que la page porte dans
 * `flash`, et ce que le filet des visites hors ligne annonce (#2048).
 *
 * Le layout authentifié et le layout invité le montent tous deux. Sans lui,
 * une page d'invité restait muette sans réseau : la déconnexion depuis la
 * vérification de l'adresse, un lien ou un formulaire de connexion échouaient
 * sans un mot, le filet ayant retiré l'erreur au rapporteur.
 */

const page = usePage()

const toasts = {
    success: { icon: 'check_circle', color: 'emerald', delay: 5000, id: null },
    error: { icon: 'error', color: 'red', delay: 8000, id: null },
}

/**
 * Le bouton que porte le toast d'erreur, avec le message auquel il appartient :
 * « Annuler » sous « Le réseau ne répond pas » (#2048). Un autre message le
 * fait disparaître, puisqu'il ne lui appartient pas.
 */
const actionDuToast = ref(null)

const actionDeLErreur = computed(() =>
    actionDuToast.value !== null && actionDuToast.value.message === page.props.flash?.error
        ? actionDuToast.value
        : null,
)

/**
 * Pose l'échéance du toast `key`. Un toast qui porte une action n'en a pas :
 * il reste jusqu'à ce que sa visite finisse, ou qu'on le ferme.
 *
 * @param {'success'|'error'} key
 */
const armerLeToast = (key) => {
    clearTimeout(toasts[key].id)

    if (key === 'error' && actionDeLErreur.value !== null) {
        return
    }

    toasts[key].id = setTimeout(() => page.props.flash && (page.props.flash[key] = null), toasts[key].delay)
}

/*
 * Chaque toast garde l'échéance posée à son apparition.
 *
 * Le watch se déclenche dès que l'une ou l'autre valeur bouge, et il ré-armait
 * alors tout toast encore affiché — pas seulement celui qui venait de changer.
 * Une erreur montrée en même temps qu'un succès voyait donc son compte à
 * rebours redémarrer quand le succès disparaissait à 5 s : elle restait 13 s au
 * lieu des 8 s configurées.
 *
 * Les deux délais sont réglés différemment à dessein — une erreur mérite d'être
 * lue plus longtemps. Le ré-armement rendait ce réglage imprévisible, puisque la
 * durée réelle dépendait de ce qui arrivait ensuite plutôt que de la nature du
 * message (#1387).
 *
 * Comparer à la valeur précédente suffit : un message réémis à l'identique passe
 * d'abord par null, quand son propre minuteur le retire, donc la transition est
 * bien vue et le minuteur bien reposé.
 */
watch(
    () => [page.props.flash?.success, page.props.flash?.error],
    (current, previous = []) => {
        current.forEach((value, i) => {
            const key = Object.keys(toasts)[i]

            if (!value || value === previous[i]) {
                return
            }

            armerLeToast(key)
        })
    },
    { immediate: true },
)

/**
 * Ce que le filet des visites hors ligne annonce (`Utils/visitesHorsLigne.js`),
 * par le toast d'erreur : une page indisponible sans réseau, une écriture sans
 * confirmation, un réseau qui ne répond pas. Le même message annoncé deux fois
 * de suite repart pour sa durée entière : sinon le second appui sur un lien
 * mort ne dirait rien.
 */
const surSansReseau = ({ detail }) => {
    const flash = page.props.flash ?? (page.props.flash = {})

    if (detail.message === null) {
        if (flash.error === detail.retire) {
            flash.error = null
        }

        return
    }

    actionDuToast.value =
        detail.annuler === null ? null : { message: detail.message, libelle: 'Annuler', faire: detail.annuler }

    if (flash.error === detail.message) {
        armerLeToast('error')

        return
    }

    flash.error = detail.message
}

const faireLActionDuToast = () => {
    const action = actionDeLErreur.value

    page.props.flash.error = null
    actionDuToast.value = null
    action?.faire()
}

onMounted(() => window.addEventListener(EVENEMENT_SANS_RESEAU, surSansReseau))

onUnmounted(() => {
    window.removeEventListener(EVENEMENT_SANS_RESEAU, surSansReseau)
    Object.values(toasts).forEach((t) => clearTimeout(t.id))
})
</script>

<template>
    <div>
        <div v-for="(cfg, type) in toasts" :key="type">
            <Transition
                enter-active-class="transition ease-out duration-300"
                enter-from-class="opacity-0 -translate-y-4"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition ease-in duration-200"
                leave-from-class="opacity-100 translate-y-0"
                leave-to-class="opacity-0 -translate-y-4"
            >
                <div
                    v-if="page.props.flash?.[type]"
                    class="z-toast fixed top-20 right-4 left-4 sm:right-6 sm:left-auto sm:w-80"
                    role="alert"
                    :aria-live="type === 'error' ? 'assertive' : 'polite'"
                >
                    <div
                        :class="[
                            'glass-panel-light flex items-center gap-3 rounded-2xl border-l-[6px] p-4 shadow-lg backdrop-blur-xl',
                            type === 'success' ? 'border-l-accent-state' : 'border-l-accent-danger',
                        ]"
                    >
                        <div
                            :class="[
                                'flex size-10 items-center justify-center rounded-xl',
                                type === 'success'
                                    ? 'bg-accent-state/10 text-accent-state-deep'
                                    : 'bg-accent-danger/10 text-accent-danger-deep',
                            ]"
                        >
                            <GlassIcon :name="cfg.icon" />
                        </div>
                        <p class="text-text-main flex-1 text-sm font-bold">
                            {{ page.props.flash[type] }}
                        </p>
                        <GlassButton
                            v-if="type === 'error' && actionDeLErreur"
                            variant="secondary"
                            size="sm"
                            dusk="action-du-toast"
                            @click="faireLActionDuToast"
                        >
                            {{ actionDeLErreur.libelle }}
                        </GlassButton>
                        <GlassIconButton
                            v-press
                            icon="close"
                            label="Fermer le message"
                            @click="page.props.flash[type] = null"
                        />
                    </div>
                </div>
            </Transition>
        </div>
    </div>
</template>

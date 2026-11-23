<script setup>
/**
 * L'accessoire de la séance en cours (#2434) : une capsule de verre au-dessus
 * de la barre du bas, sur les quatre onglets, jamais sur la page de la séance.
 * Un seul exemplaire par page : il porte le `dusk`, le repos et l'annonce de
 * sa fin. Sur bureau, sans barre du bas, il reste dans le flux de la page.
 * « Reprendre » n'est pas un second bouton : c'est la capsule entière qui
 * mène à la séance.
 *
 * Une séance démarrée sans réseau, dont la création attend la file (#2049,
 * `workout.enAttenteDe`), n'a pas d'adresse que le serveur connaisse encore :
 * le bandeau la nomme par l'adresse de sa clé, et l'ouvre côté client
 * (`ouvrir`, que le layout tient de `useSeanceEnCoursDeLAppareil`), au lieu
 * d'une visite que le serveur, injoignable, ne servirait pas.
 *
 * Un repos laissé par la page de séance (#2434, `Utils/reposEnCours.js`) se lit
 * ici, à la place de la durée : le décompte continue sur l'horloge, ailleurs
 * dans l'application. Il s'annonce une fois, à sa fin (« Repos terminé »,
 * `role="status"`), jamais à chaque seconde, puis le dépôt s'efface.
 */
import { Link } from '@inertiajs/vue3'
import AnneauDuRepos from '@/Components/Workout/AnneauDuRepos.vue'
import { computed, ref, watch, onMounted, onUnmounted } from 'vue'
import { compte, entier } from '@/Utils/nombre'
import { adresseDUneSeanceEnAttente } from '@/Utils/seanceEnAttente'
import { jourEtMois } from '@/Utils/date'
import { SEUIL_D_UNE_SEANCE_OUBLIEE_S } from '@/Utils/seanceOubliee'
import { DELAI_DE_L_ANNONCE_MS, effacerLeRepos, noterLAnnonceDuRepos, secondesRestantes } from '@/Utils/reposEnCours'
import { triggerHaptic } from '@/composables/useHaptics'

const props = defineProps({
    workout: { type: Object, required: true },
    /** Le repos que la page de séance a laissé (`reposDuCompte`), ou null. */
    repos: { type: Object, default: null },
})

const emit = defineEmits(['ouvrir'])

/** La séance attend la file : elle s'ouvre côté client. */
const enAttente = computed(() => typeof props.workout.enAttenteDe === 'string')

const lien = computed(() => (enAttente.value ? 'a' : Link))

/** L'identifiant que le serveur a donné à la séance ; une séance en attente n'en a pas encore. */
const realWorkoutId = computed(() => (enAttente.value ? null : props.workout.id))

const adresse = computed(() =>
    enAttente.value
        ? (adresseDUneSeanceEnAttente(props.workout.idempotency_key) ?? '#')
        : route('workouts.show', { workout: realWorkoutId.value }),
)

/** @param {MouseEvent} evenement */
const ouvrir = (evenement) => {
    if (!enAttente.value) {
        return
    }

    evenement.preventDefault()
    emit('ouvrir', props.workout)
}

const elapsed = ref('')
let intervalId = null

/** L'horloge du décompte du repos, avancée par le tic de la durée. */
const maintenant = ref(Date.now())

/** Ce qu'il reste au repos : sa fin tombe à zéro, qu'il soit en pause (reste figé) ou en marche. */
const resteDuRepos = computed(() => (props.repos === null ? null : secondesRestantes(props.repos, maintenant.value)))

const reposAffiche = computed(() => {
    if (resteDuRepos.value === null || resteDuRepos.value === 0) {
        return null
    }

    return `${props.repos.echeance === null ? 'Repos en pause' : 'Repos'} ${Math.floor(resteDuRepos.value / 60)}:${String(resteDuRepos.value % 60).padStart(2, '0')}`
})

/** Ce qu'il reste du repos sur sa durée, de 0 à 1 : l'anneau de la capsule. */
const fractionDuRepos = computed(() => {
    if (reposAffiche.value === null || !props.repos.duree) {
        return 0
    }

    return Math.min(1, Math.max(0, resteDuRepos.value / props.repos.duree))
})

const reposTermine = computed(() => resteDuRepos.value === 0)

let fermetureDuRepos = null
let annonceCommencee = false

/*
 * La fin se dit une fois : le retour haptique ne part qu'au passage à zéro
 * (`avant === false`), pas quand un bandeau remonté sur une autre page retrouve
 * un repos déjà fini. Le dépôt s'efface après l'annonce, sans effacer celui qui
 * l'aurait remplacé entre-temps.
 */
watch(
    reposTermine,
    (fini, avant) => {
        if (!fini || fermetureDuRepos !== null) {
            return
        }

        if (avant === false) {
            triggerHaptic('timer')
        }

        annonceCommencee = true

        fermetureDuRepos = setTimeout(() => {
            fermetureDuRepos = null
            annonceCommencee = false

            if (reposTermine.value) {
                effacerLeRepos()
            }
        }, DELAI_DE_L_ANNONCE_MS)
    },
    { immediate: true },
)

const updateElapsed = () => {
    maintenant.value = Date.now()
    const start = new Date(props.workout.started_at)
    const now = new Date()
    // Une horloge décalée ou un fuseau changé ne donnent jamais une durée négative.
    const diff = Math.max(0, Math.floor((now - start) / 1000))
    if (diff > SEUIL_D_UNE_SEANCE_OUBLIEE_S) {
        elapsed.value = `dès le ${jourEtMois(start)}`

        return
    }

    const h = Math.floor(diff / 3600)
    const m = Math.floor((diff % 3600) / 60)
    const s = diff % 60
    elapsed.value =
        h > 0 ? `${entier(h)} h ${String(m).padStart(2, '0')} min` : `${m} min ${String(s).padStart(2, '0')} s`
}

onMounted(() => {
    updateElapsed()
    intervalId = setInterval(updateElapsed, 1000)
})

onUnmounted(() => {
    if (intervalId) clearInterval(intervalId)
    clearTimeout(fermetureDuRepos)

    // Partie avant l'effacement : le bandeau de la page suivante ne redit pas « Repos terminé ».
    if (annonceCommencee) {
        noterLAnnonceDuRepos()
    }
})
</script>

<template>
    <component
        :is="lien"
        v-press
        :href="adresse"
        @click="ouvrir"
        class="verre-epais rounded-capsule text-text-main focus-visible:ring-accent-primary focus-visible:ring-offset-surface-page flex min-h-16 items-center gap-2 py-2 pr-2 pl-5 transition focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none active:scale-[0.98]"
        dusk="active-workout-banner"
    >
        <AnneauDuRepos
            v-if="reposAffiche !== null"
            :fraction="fractionDuRepos"
            class="-ml-2 size-10"
            dusk="anneau-du-repos"
        />
        <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-medium">{{ workout.name || 'Séance' }}</span>
            <span class="text-text-muted block truncate text-xs tabular-nums">
                <span dusk="active-workout-elapsed">{{ reposAffiche ?? elapsed }}</span>
                <template v-if="workout.workout_lines_count">
                    · {{ compte(workout.workout_lines_count, 'exo', 'exos') }}
                </template>
            </span>
        </span>
        <span class="sr-only" role="status">{{ reposTermine ? 'Repos terminé' : '' }}</span>
        <span
            class="accent-fill rounded-capsule flex min-h-11 shrink-0 items-center px-4 text-sm font-medium"
            aria-hidden="true"
        >
            Reprendre
        </span>
    </component>
</template>

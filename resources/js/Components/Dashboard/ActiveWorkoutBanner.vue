<script setup>
/**
 * Le bandeau de la séance en cours.
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
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { computed, ref, watch, onMounted, onUnmounted } from 'vue'
import { compte, entier } from '@/Utils/nombre'
import { adresseDUneSeanceEnAttente } from '@/Utils/seanceEnAttente'
import { jourEtMois } from '@/Utils/date'
import { SEUIL_D_UNE_SEANCE_OUBLIEE_S } from '@/Utils/seanceOubliee'
import { DELAI_DE_L_ANNONCE_MS, effacerLeRepos, secondesRestantes } from '@/Utils/reposEnCours'
import { triggerHaptic } from '@/composables/useHaptics'

const props = defineProps({
    workout: { type: Object, required: true },
    /** Une ligne au lieu d'une carte : partout où la séance n'est pas le sujet. */
    compact: { type: Boolean, default: false },
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

const reposTermine = computed(() => resteDuRepos.value === 0)

let fermetureDuRepos = null

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

        fermetureDuRepos = setTimeout(() => {
            fermetureDuRepos = null

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
})
</script>

<!--
    Le degrade du bandeau a ses propres jetons, et c'est le sujet.

    Il portait `emerald-500`, `teal-500` et `cyan-500`. La conversion les a
    envoyes sur `accent-state` et `accent-info` — les jetons VIFS, bien plus
    clairs — et le texte blanc est passe de 2,5:1 a 1,2:1 : le bandeau
    s'affichait sans qu'un mot s'y lise.

    Les deux couches ne portent pas le meme degrade : celle du survol inverse
    l'ordre des trois tons. Elles ont un temps ete identiques — la conversion
    avait envoye `emerald-500/teal-500/cyan-500` et `emerald-400/teal-400/
    cyan-400` sur les memes jetons — et le survol ne faisait alors plus rien du
    tout, en fondant une couche vers une copie d'elle-meme.

    La charte porte donc `--color-session-*`, les trois tons d'origine
    assombris du minimum necessaire pour que le blanc tienne 4,52:1. Ils ne
    rejoignent aucune famille existante : une seance ouverte n'est ni un accent
    — elle ne demande rien — ni une categorie. C'est une identite, et elle
    meritait ses propres noms plutot que d'emprunter ceux d'a cote.
-->
<template>
    <component
        :is="lien"
        v-if="compact"
        v-press
        :href="adresse"
        @click="ouvrir"
        class="from-session-from via-session-via to-session-to border-session-from/40 text-text-on-dark-accent focus-visible:ring-session-from focus-visible:ring-offset-surface-page flex min-h-12 items-center gap-3 rounded-2xl border bg-linear-to-r px-4 py-2 transition focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none active:scale-[0.98]"
        dusk="active-workout-banner"
    >
        <GlassIcon name="fitness_center" size="sm" fill />
        <span class="min-w-0 flex-1 truncate text-sm font-black uppercase italic">{{ workout.name || 'Séance' }}</span>
        <span class="flex items-center gap-1 text-xs font-bold tabular-nums" dusk="active-workout-elapsed">
            <GlassIcon name="timer" size="xs" />
            {{ reposAffiche ?? elapsed }}
        </span>
        <span class="sr-only" role="status">{{ reposTermine ? 'Repos terminé' : '' }}</span>
        <GlassIcon name="chevron_right" size="sm" class="opacity-70" />
    </component>
    <component
        :is="lien"
        v-else
        v-press
        :href="adresse"
        @click="ouvrir"
        class="animate-fade-in group border-session-from/40 focus-visible:ring-session-from focus-visible:ring-offset-surface-page relative block overflow-hidden rounded-3xl border-2 transition duration-300 hover:-translate-y-1 hover:shadow-2xl focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none active:scale-[0.98]"
        dusk="active-workout-banner"
    >
        <!-- Animated gradient background -->
        <div class="from-session-from via-session-via to-session-to absolute inset-0 bg-linear-to-r opacity-90"></div>
        <div
            class="from-session-from via-session-via to-session-to absolute inset-0 bg-linear-to-r opacity-0 transition-opacity duration-500 group-hover:opacity-90"
        ></div>

        <!-- Pulse ring effect -->
        <div class="absolute top-4 right-4 flex items-center gap-2">
            <span class="relative flex size-3">
                <span
                    class="bg-surface-card absolute inline-flex h-full w-full animate-ping rounded-full opacity-75"
                ></span>
                <span class="bg-surface-card relative inline-flex size-3 rounded-full"></span>
            </span>
            <span class="text-text-on-dark-accent/90 text-xs font-black tracking-widest uppercase">En cours</span>
        </div>

        <div class="relative z-10 flex items-center gap-4 p-5">
            <!-- Icon -->
            <div
                class="bg-surface-card/20 flex size-14 shrink-0 items-center justify-center rounded-2xl backdrop-blur-sm"
            >
                <GlassIcon name="fitness_center" size="lg" fill class="text-text-on-dark-accent" />
            </div>

            <!-- Content -->
            <div class="min-w-0 flex-1">
                <p class="sur-titre text-text-on-dark-accent/70">Séance active</p>
                <h3 class="text-text-on-dark-accent titre-carte truncate">
                    {{ workout.name || 'Séance' }}
                </h3>
                <div class="mt-1 flex items-center gap-3">
                    <span
                        class="text-text-on-dark-accent/80 flex items-center gap-1 text-sm font-bold"
                        dusk="active-workout-elapsed"
                    >
                        <GlassIcon name="timer" size="xs" />
                        {{ reposAffiche ?? elapsed }}
                    </span>
                    <span class="sr-only" role="status">{{ reposTermine ? 'Repos terminé' : '' }}</span>
                    <span
                        v-if="workout.workout_lines_count"
                        class="text-text-on-dark-accent/80 flex items-center gap-1 text-sm font-bold"
                    >
                        <GlassIcon name="exercise" size="xs" />
                        {{ compte(workout.workout_lines_count, 'exo', 'exos') }}
                    </span>
                </div>
            </div>

            <!-- Arrow -->
            <div class="flex shrink-0 items-center">
                <GlassIcon
                    name="arrow_forward_ios"
                    class="text-text-on-dark-accent/60 group-hover:text-text-on-dark-accent transition-transform duration-300 group-hover:translate-x-1"
                />
            </div>
        </div>
    </component>
</template>

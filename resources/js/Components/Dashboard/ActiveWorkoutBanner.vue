<script setup>
import { Link } from '@inertiajs/vue3'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { ref, onMounted, onUnmounted } from 'vue'

const props = defineProps({
    workout: { type: Object, required: true },
    /** Une ligne au lieu d'une carte : partout où la séance n'est pas le sujet. */
    compact: { type: Boolean, default: false },
})

const elapsed = ref('')
let intervalId = null

const updateElapsed = () => {
    const start = new Date(props.workout.started_at)
    const now = new Date()
    // Une horloge décalée ou un fuseau changé ne donnent jamais une durée négative.
    const diff = Math.max(0, Math.floor((now - start) / 1000))
    const h = Math.floor(diff / 3600)
    const m = Math.floor((diff % 3600) / 60)
    const s = diff % 60
    elapsed.value = h > 0 ? `${h}h ${String(m).padStart(2, '0')}m` : `${m}m ${String(s).padStart(2, '0')}s`
}

onMounted(() => {
    updateElapsed()
    intervalId = setInterval(updateElapsed, 1000)
})

onUnmounted(() => {
    if (intervalId) clearInterval(intervalId)
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
    <Link
        v-if="compact"
        v-press
        :href="route('workouts.show', { workout: workout.id })"
        class="from-session-from via-session-via to-session-to border-session-from/40 text-text-on-dark-accent focus-visible:ring-session-from focus-visible:ring-offset-surface-page flex min-h-12 items-center gap-3 rounded-2xl border bg-linear-to-r px-4 py-2 transition focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none active:scale-[0.98]"
        dusk="active-workout-banner"
    >
        <GlassIcon name="fitness_center" size="sm" fill />
        <span class="min-w-0 flex-1 truncate text-sm font-black uppercase italic">{{ workout.name || 'Séance' }}</span>
        <span class="flex items-center gap-1 text-xs font-bold tabular-nums">
            <GlassIcon name="timer" size="xs" />
            {{ elapsed }}
        </span>
        <GlassIcon name="chevron_right" size="sm" class="opacity-70" />
    </Link>
    <Link
        v-else
        v-press
        :href="route('workouts.show', { workout: workout.id })"
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
                class="bg-surface-card/20 flex size-14 shrink-0 items-center justify-center rounded-2xl backdrop-blur-sm transition-transform duration-300 group-hover:scale-110"
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
                    <span class="text-text-on-dark-accent/80 flex items-center gap-1 text-sm font-bold">
                        <GlassIcon name="timer" size="xs" />
                        {{ elapsed }}
                    </span>
                    <span
                        v-if="workout.workout_lines_count"
                        class="text-text-on-dark-accent/80 flex items-center gap-1 text-sm font-bold"
                    >
                        <GlassIcon name="exercise" size="xs" />
                        {{ workout.workout_lines_count }} exos
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
    </Link>
</template>

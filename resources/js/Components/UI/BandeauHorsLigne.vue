<script setup>
/**
 * Le bandeau d'une page servie depuis sa copie hors ligne (#2048, partie E).
 *
 * Une page de salle ouverte sans réseau, ou après cinq secondes sans réponse,
 * vient de la copie que l'appareil en garde (`Utils/visitesHorsLigne.js`) :
 * ce bandeau le dit, et date la copie, puisque ce qui a changé depuis n'y
 * apparaît pas. « Réessayer » recharge la page en entier (`reessayerLaCopie`),
 * comme le retour du réseau le fait de lui-même, et dit ce qui l'en empêche.
 * Une réponse complète du serveur retire la marque de la copie, et le bandeau
 * avec elle.
 *
 * Une carte pleine bordée de l'ambre de la charte : un état à remarquer, pas
 * une alerte. Deux lignes, l'état puis la date, pour que le bouton tienne à
 * côté à 320 px (#2434).
 *
 * Il porte aussi, caché, ce qui dit pourquoi un bouton d'écriture est
 * désactivé sur la copie (`useCopieHorsLigne`) : le bouton le vise par
 * `aria-describedby`, et un texte caché que l'on vise se lit quand même. Hors
 * de la région annoncée : la séance, elle, écrit encore par sa file.
 */
import { computed } from 'vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { RAISON_RESEAU_LENT, dateDeLaCopie } from '@/Utils/copiesServies'
import { dateSansJour, heureCourte } from '@/Utils/date'
import { reessayerLaCopie, rechargementDeLaCopie } from '@/Utils/visitesHorsLigne'
import {
    ID_DE_LA_RAISON_DES_ECRITURES,
    RAISON_DES_ECRITURES_SUR_UNE_COPIE,
    useCopieHorsLigne,
} from '@/composables/useCopieHorsLigne'

const { marque } = useCopieHorsLigne()

/** « Hors ligne », ou « Réseau lent » quand la copie est servie faute de réponse. */
const titre = computed(() => (marque.value?.raison === RAISON_RESEAU_LENT ? 'Réseau lent' : 'Hors ligne'))

/** « Copie du 20 nov. à 05:02 », avec l'année quand ce n'est pas la courante. */
const dateDeLaPage = computed(() => {
    const instant = dateDeLaCopie(marque.value)

    if (instant === null) {
        return "Copie de l'appareil"
    }

    const date = new Date(instant)

    return `Copie du ${dateSansJour(date)} à ${heureCourte(date)}`
})
</script>

<template>
    <div v-if="marque" class="mb-4" dusk="bandeau-hors-ligne">
        <div
            class="bg-surface-card border-accent-warning/60 grid grid-cols-[auto_1fr_auto] items-center gap-x-2 rounded-2xl border p-3"
            role="status"
            aria-live="polite"
        >
            <GlassIcon name="cloud_off" size="sm" class="text-accent-warning-deep" />
            <p class="text-text-main col-start-2 text-sm leading-5 font-semibold" dusk="bandeau-hors-ligne-titre">
                {{ titre }}
            </p>
            <p
                class="text-text-muted col-span-2 col-start-2 text-xs leading-4 font-medium"
                dusk="bandeau-hors-ligne-date"
            >
                {{ dateDeLaPage }}
            </p>
            <GlassButton
                variant="secondary"
                size="sm"
                class="col-start-3 row-start-1 min-h-8! px-3! py-1! font-sans text-sm font-medium tracking-normal normal-case not-italic before:-inset-y-[7px]!"
                dusk="reessayer-hors-ligne"
                :loading="rechargementDeLaCopie"
                @click="reessayerLaCopie()"
            >
                Réessayer
            </GlassButton>
        </div>
        <p :id="ID_DE_LA_RAISON_DES_ECRITURES" hidden>{{ RAISON_DES_ECRITURES_SUR_UNE_COPIE }}</p>
    </div>
</template>

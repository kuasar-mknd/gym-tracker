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
 * Il porte aussi, caché, ce qui dit pourquoi un bouton d'écriture est
 * désactivé sur la copie (`useCopieHorsLigne`) : le bouton le vise par
 * `aria-describedby`, et un texte caché que l'on vise se lit quand même. Hors
 * de la région annoncée : la séance, elle, écrit encore par sa file.
 */
import { computed } from 'vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { RAISON_RESEAU_LENT, dateDeLaCopie } from '@/Utils/copiesServies'
import { dateAvecJour, heureCourte } from '@/Utils/date'
import { reessayerLaCopie, rechargementDeLaCopie } from '@/Utils/visitesHorsLigne'
import {
    ID_DE_LA_RAISON_DES_ECRITURES,
    RAISON_DES_ECRITURES_SUR_UNE_COPIE,
    useCopieHorsLigne,
} from '@/composables/useCopieHorsLigne'

const { marque } = useCopieHorsLigne()

/** « Hors ligne · copie du dim. 18 oct. 2026 à 18:04 », ou « Le réseau ne répond pas · … ». */
const titre = computed(() => {
    const instant = dateDeLaCopie(marque.value)
    const etat = marque.value?.raison === RAISON_RESEAU_LENT ? 'Le réseau ne répond pas' : 'Hors ligne'

    if (instant === null) {
        return `${etat} · copie gardée sur l'appareil`
    }

    const date = new Date(instant)

    return `${etat} · copie du ${dateAvecJour(date)} à ${heureCourte(date)}`
})
</script>

<template>
    <div v-if="marque" class="mb-6" dusk="bandeau-hors-ligne">
        <div class="info-fill flex items-start gap-3 rounded-2xl p-4 shadow-lg" role="status" aria-live="polite">
            <GlassIcon name="cloud_off" class="shrink-0" />
            <div class="min-w-0 flex-1 space-y-1">
                <p class="text-sm font-bold" dusk="bandeau-hors-ligne-titre">{{ titre }}</p>
                <p class="text-sm font-medium">Ce qui a changé depuis n'apparaît pas ici.</p>
            </div>
            <GlassButton
                size="sm"
                icon="refresh"
                class="shrink-0"
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

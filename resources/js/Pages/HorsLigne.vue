<script setup>
/**
 * Ce qui s'ouvre sans réseau (#2048, partie F).
 *
 * La coquille hors ligne monte cette page à une adresse qui n'a pas de copie
 * (une page qui n'est pas gardée pour la salle, ou une page gardée jamais
 * ouverte sous cette session), quand l'appareil en garde d'autres pour le
 * compte (`Utils/demarrageHorsLigne.js`). Aucun contrôleur ne la rend : elle
 * vient de la copie la plus récente, ses props partagées, son `auth` et sa
 * table des routes, et liste les copies que le magasin a lues
 * (`pagesSansReseau`), dans l'ordre de la liste blanche. Un lien vers l'une
 * d'elles est une visite d'Inertia, que l'application sert depuis sa copie
 * sans réseau (`Utils/visitesHorsLigne.js`).
 *
 * Elle porte la marque d'une copie : le bandeau la date, et au retour du
 * réseau elle se recharge en entier, ce qui rend la page de son adresse.
 * Servie au délai (marque `lent`), elle propose d'attendre la réponse : le
 * worker lève alors le délai de l'adresse pendant une minute, puis la page se
 * recharge (`attendreLaReponse`).
 */
import { computed, ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { RAISON_RESEAU_LENT } from '@/Utils/copiesServies'
import { dateAvecJour, heureCourte } from '@/Utils/date'
import { attendreLaReponse } from '@/Utils/messagesDuWorker'
import { useCopieHorsLigne } from '@/composables/useCopieHorsLigne'

const props = defineProps({
    /** Les copies que l'appareil garde pour le compte : `{ chemin, composant, copieDu, enCours }`. */
    pagesSansReseau: {
        type: Array,
        default: () => [],
    },
})

/** Le nom de chaque page gardée, tel que la page le montre. */
const NOMS = Object.freeze({
    Dashboard: 'Accueil',
    'Workouts/Index': 'Séances',
    'Workouts/Templates/Index': 'Modèles',
    'Exercises/Index': 'Exercices',
    'Profile/Index': 'Profil',
    'Tools/Index': 'Outils',
    'Tools/IntervalTimer': "Minuteur d'intervalles",
    'Tools/PlateCalculator': 'Calculateur de plaques',
    'Tools/OneRepMax': 'Calculateur 1RM',
    'Tools/WarmupCalculator': "Calculateur d'échauffement",
})

const { marque } = useCopieHorsLigne()

/** Servie au délai : le réseau répond peut-être, lentement. */
const auDelai = computed(() => marque.value?.raison === RAISON_RESEAU_LENT)

/** Le bouton « Attendre la réponse » a été pressé : la page se recharge. */
const enAttente = ref(false)

/**
 * Le nom d'une copie : la séance dit si elle est en cours.
 *
 * @param {{ composant: string, enCours?: boolean }} page
 */
const nomDe = (page) => {
    if (page.composant === 'Workouts/Show') {
        return page.enCours ? 'Séance en cours' : 'Séance terminée'
    }

    return NOMS[page.composant] ?? page.composant
}

const pages = computed(() =>
    props.pagesSansReseau.map((page) => {
        const date = new Date(page.copieDu)

        return { ...page, nom: nomDe(page), date: `copie du ${dateAvecJour(date)} à ${heureCourte(date)}` }
    }),
)

const attendre = () => {
    enAttente.value = true
    attendreLaReponse()
}
</script>

<template>
    <Head title="Hors ligne" />

    <AuthenticatedLayout page-title="Hors ligne" largeur="etroite">
        <div class="space-y-6" dusk="page-hors-ligne-sans-copie">
            <GlassCard class="space-y-3 text-center">
                <GlassIcon name="cloud_off" size="lg" class="text-text-muted" />
                <h2 class="titre-section">Cette page n'est pas disponible sans réseau</h2>
                <p class="text-text-muted text-sm font-medium">
                    {{
                        auDelai
                            ? "Le réseau ne répond pas, et l'appareil n'en garde pas de copie."
                            : "L'appareil n'en garde pas de copie."
                    }}
                    Ce que tu as saisi pendant une séance reste sur l'appareil, et partira au retour du réseau.
                </p>
                <GlassButton
                    v-if="auDelai"
                    variant="secondary"
                    dusk="attendre-la-reponse"
                    :loading="enAttente"
                    @click="attendre"
                >
                    Attendre la réponse
                </GlassButton>
            </GlassCard>

            <GlassCard class="space-y-4">
                <h2 class="titre-carte">Ce qui s'ouvre sans réseau</h2>

                <ul class="space-y-2">
                    <li v-for="page in pages" :key="page.chemin">
                        <Link
                            :href="page.chemin"
                            class="hover:bg-surface-sunken focus-visible:ring-accent-primary min-h-touch flex items-center justify-between gap-4 rounded-xl px-3 py-2 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                            dusk="page-sans-reseau"
                        >
                            <span class="text-text-main text-sm font-bold">{{ page.nom }}</span>
                            <span class="text-text-muted text-xs font-medium">{{ page.date }}</span>
                        </Link>
                    </li>
                </ul>
            </GlassCard>
        </div>
    </AuthenticatedLayout>
</template>

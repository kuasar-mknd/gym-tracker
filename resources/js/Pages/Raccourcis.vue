<script setup>
/**
 * Ce que le clavier sait faire, écrit quelque part.
 *
 * L'application en avait un seul — ⌘K dans la bibliothèque — annoncé par une
 * pastille dans le champ de recherche et listé nulle part (#1817). Un raccourci
 * que personne ne peut découvrir n'existe qu'à moitié.
 */
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import { Head } from '@inertiajs/vue3'
import { onMounted, ref } from 'vue'

/** Le libellé de la touche de commande, selon la machine qui lit la page. */
const commande = ref('Ctrl')

onMounted(() => {
    commande.value = /Mac|iPhone|iPad/.test(navigator.platform ?? '') ? '⌘' : 'Ctrl'
})

const groupes = [
    {
        titre: 'Partout',
        raccourcis: [{ touches: ['?'], quoi: 'Ouvrir cette page' }],
    },
    {
        titre: 'Bibliothèque d’exercices',
        raccourcis: [
            { touches: ['CMD', 'K'], quoi: 'Aller au champ de recherche' },
            { touches: ['Échap'], quoi: 'Vider la recherche' },
        ],
    },
    {
        titre: 'Séance en cours',
        raccourcis: [{ touches: ['CMD', 'Entrée'], quoi: 'Ajouter une série au dernier exercice' }],
    },
]
</script>

<template>
    <Head title="Raccourcis clavier" />

    <AuthenticatedLayout page-title="Raccourcis" show-back back-route="profile.index" largeur="etroite">
        <div class="space-y-6">
            <p class="text-text-muted text-sm font-medium">
                Les raccourcis n'agissent jamais pendant que tu écris dans un champ.
            </p>

            <GlassCard v-for="groupe in groupes" :key="groupe.titre" class="space-y-4">
                <h2 class="titre-carte">{{ groupe.titre }}</h2>

                <ul class="space-y-3">
                    <li
                        v-for="raccourci in groupe.raccourcis"
                        :key="raccourci.quoi"
                        class="flex items-center justify-between gap-4"
                    >
                        <span class="text-text-main text-sm font-medium">{{ raccourci.quoi }}</span>
                        <span class="flex shrink-0 items-center gap-1">
                            <kbd
                                v-for="touche in raccourci.touches"
                                :key="touche"
                                class="border-border bg-surface-sunken text-text-main rounded-lg border px-2 py-1 text-xs font-black"
                            >
                                {{ touche === 'CMD' ? commande : touche }}
                            </kbd>
                        </span>
                    </li>
                </ul>
            </GlassCard>
        </div>
    </AuthenticatedLayout>
</template>

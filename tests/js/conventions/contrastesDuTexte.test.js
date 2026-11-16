import { it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { jsRoot } from './sourceFiles'

/**
 * `text-text-muted` plein tient 4,96:1 sur la carte ; son atténuation à 30, 40, 50 ou 60 % tombe
 * sous 3:1 et ne se lit plus (#2355). Un texte lisible prend le jeton plein. Restent permis un
 * pictogramme décoratif (une icône n'est pas lue) et un texte d'exemple de champ (`placeholder:`).
 */
const FICHIERS = [
    'Components/Workout/CarteDeSeance.vue',
    'Pages/Workouts/Templates/Index.vue',
    'Pages/Profile/Partials/UpdateNotificationPreferencesForm.vue',
    'Components/Templates/TemplateForm.vue',
    'Pages/Calendar/Index.vue',
    'Pages/Exercises/Index.vue',
    'Pages/Measurements/Index.vue',
    'Pages/Measurements/Parts/Show.vue',
]

it('ne grise jamais un texte lu par une opacité', () => {
    const fautifs = FICHIERS.flatMap((fichier) =>
        readFileSync(join(jsRoot, fichier), 'utf8')
            .split('\n')
            .flatMap((ligne, index) =>
                /(?<!placeholder:)text-text-muted\/\d+/.test(ligne) && !ligne.includes('<GlassIcon')
                    ? [`${fichier}:${index + 1}`]
                    : [],
            ),
    )

    expect(fautifs, 'écrire `text-text-muted` sans opacité').toEqual([])
})

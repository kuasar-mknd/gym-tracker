import { it, expect } from 'vitest'
import { readFileSync } from 'node:fs'

import { collectSourceFiles, jsRoot } from './sourceFiles'

/**
 * Les titres avaient 28 combinaisons de classes, et le même rôle — le titre
 * d'une carte — s'écrivait tantôt en capitales italiques, tantôt en casse de
 * phrase, d'un écran à l'autre (#1783). La charte porte trois rôles : un titre
 * en choisit un, et n'écrit plus sa typographie.
 */
const ROLES = ['titre-ecran', 'titre-plat', 'etiquette']

/**
 * Ce qui place ou colore, par opposition à ce qui compose. `sr-only` cache aux
 * yeux sans rien composer : le titre de bureau que le layout pose pour les
 * lecteurs d'écran n'a pas de typographie à choisir (#2120).
 */
const MISE_EN_PAGE =
    /^(m[btlrxy]?-|p[btlrxy]?-|gap-|space-|w-|max-w-|min-w-|flex|grid|truncate|line-clamp|souris:line-clamp|shrink|grow|inline|block|hidden|sr-only|sm:block|bureau:block|text-center|text-left|text-right|break-|overflow|whitespace|self-|items-|justify-|sticky|top-|z-|border|bg-|backdrop-|rounded|shadow|group|relative|absolute)/

const COULEUR =
    /^(text-(text|accent|surface)-|text-transparent|bg-clip-text|bg-linear|from-|via-|to-|text-gradient|drop-shadow)/

/**
 * `titre-ecran`, `titre-plat` et `etiquette` sont les rôles du verre : casse de
 * phrase, graisse 600. Les quatre premiers restent ceux des autres pages, que
 * des parcours Dusk lisent en capitales.
 */
it('ne compose un titre que par un rôle de la charte', () => {
    const fautifs = collectSourceFiles({ extensions: ['.vue'] }).flatMap((fichier) => {
        const source = readFileSync(fichier, 'utf8')

        return [...source.matchAll(/<(h[1-3])\b[^>]*?\sclass="([^"]*)"/g)]
            .map((trouve) => ({
                balise: trouve[1],
                typographie: trouve[2]
                    .split(/\s+/)
                    .filter((classe) => classe !== '' && !MISE_EN_PAGE.test(classe) && !COULEUR.test(classe)),
                ligne: source.slice(0, trouve.index).split('\n').length,
            }))
            .filter(
                ({ typographie }) =>
                    typographie.length > 1 || (typographie.length === 1 && !ROLES.includes(typographie[0])),
            )
            .map(
                ({ balise, typographie, ligne }) =>
                    `${fichier.replace(jsRoot, 'resources/js')}:${ligne} — ${balise} « ${typographie.join(' ')} »`,
            )
    })

    expect(
        fautifs,
        `un titre porte un rôle de la charte (titre-ecran, titre-plat, etiquette), et rien d'autre`,
    ).toEqual([])
})

/**
 * Les anciens rôles en capitales italiques ont disparu d'app.css : leur nom ne
 * revient dans aucune source de resources/js.
 */
it('ne réemploie aucun des anciens rôles de titre', () => {
    const fautifs = collectSourceFiles({ extensions: ['.vue', '.js'] })
        .filter((fichier) =>
            /\b(?:titre-page|titre-section|titre-carte|sur-titre|font-display-label)\b/.test(
                readFileSync(fichier, 'utf8'),
            ),
        )
        .map((fichier) => fichier.replace(jsRoot, 'resources/js'))

    expect(fautifs).toEqual([])
})

/**
 * La typographie des libellés et des exemples de champ (#2355) : « (facultatif) », « Ex. : » avec ses deux espaces, une virgule décimale dans un
 * exemple de nombre (fr-CH, `Utils/nombre.js`) et le signe « × » pour une
 * quantité, jamais un « x » de clavier.
 */
it('écrit les libellés et les exemples de champ à la française', () => {
    const regles = [
        [/\([Oo]ptionnel\)/, 'un champ qu’on peut laisser vide se dit « (facultatif) »'],
        [
            /Enregistrer les modifications|Mettre à jour le modèle|Enregistrer le modèle|Créer l['’](?:objectif|exercice)/,
            'la même action se dit « Enregistrer » (édition) ou « Créer » (création)',
        ],
        [/:label="[^"]*'[A-ZÉ][a-zéèêàâ]+ [A-ZÉ][a-zéèêàâ]+'/, 'un libellé dynamique s’écrit en casse de phrase'],
        [/\}\}x\b/, 'une quantité s’écrit « × »'],
        [/\bBF\b/, 'la masse grasse se dit « MG »'],
        [
            /\bProgrammes?\b/i,
            'un modèle de séance se dit « Modèle », pas « Programme »',
            (fichier) => fichier.endsWith('.vue'),
        ],
        [
            /\bProgrammes?\b/,
            'un modèle de séance se dit « Modèle », pas « Programme » (le programme d’un minuteur, en minuscule, reste du code)',
            (fichier) => !fichier.endsWith('.vue'),
        ],
        [/placeholder="Ex:/, '« Ex. : » et non « Ex: »'],
        [/placeholder="\d+\.\d+"/, 'un exemple de nombre prend la virgule'],
        [/>\s*x \{\{/, 'une quantité s’écrit « × »'],
    ]

    const fautifs = collectSourceFiles({ extensions: ['.vue', '.js'] }).flatMap((fichier) => {
        const source = readFileSync(fichier, 'utf8')

        return regles
            .filter(([, , vise = () => true]) => vise(fichier))
            .flatMap(([motif, raison]) =>
                source
                    .split('\n')
                    .flatMap((ligne, index) =>
                        motif.test(ligne)
                            ? [`${fichier.replace(jsRoot, 'resources/js')}:${index + 1} — ${raison}`]
                            : [],
                    ),
            )
    })

    expect(fautifs).toEqual([])
})

/**
 * Un bouton qui crée se dit « Créer », un bouton qui modifie « Enregistrer »
 * (#2355). Les deux formulaires de création qui disaient encore « Enregistrer »
 * (une plaque) et « Ajouter » (un complément) sont nommés ici : le libellé d'un
 * bouton se lit dans son gabarit, que la garde ne peut pas deviner création ou
 * édition d'après le seul mot.
 */
it('nomme « Créer » le bouton des formulaires de création', () => {
    const creations = [
        ['Pages/Tools/PlateCalculator.vue', /@click="savePlate"[^>]*>\s*([^<]*?)\s*</],
        [
            'Pages/Supplements/Index.vue',
            /type="submit" variant="primary" class="flex-1" :loading="form\.processing">\s*([^<]*?)\s*</,
        ],
    ]

    const libelles = creations.map(([fichier, motif]) => [
        fichier,
        readFileSync(`${jsRoot}/${fichier}`, 'utf8').match(motif)?.[1],
    ])

    expect(libelles).toEqual(creations.map(([fichier]) => [fichier, 'Créer']))
})

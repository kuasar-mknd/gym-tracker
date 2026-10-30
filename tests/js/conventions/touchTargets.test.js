import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'

import { CIBLE, cible, icone, mesurable, seRetrecit } from './ciblesTactiles'
import { elementsDuGabarit, gabarit } from './gabarits'
import { collectSourceFiles, jsRoot, visibleText } from './sourceFiles'

/** `<button>` dont le contenu visible se réduit à une ligature. */
const boutonsIcone = /<button\b(?<attributs>(?:[^>"']|"[^"]*"|'[^']*')*?)>(?<corps>.*?)<\/button>/gs

/** Les trois formes d'icône du dépôt : `GlassIcon`, la ligature Material Symbols écrite à la main, le `<svg>` en ligne. */
const ICONE_DANS = /<GlassIcon\b|material-symbols|<svg\b/

/**
 * `GlassButton`, `GlassIconButton` et `GlassInput` DÉFINISSENT l'affordance ;
 * les mesurer reviendrait à mesurer la règle avec elle-même. Leurs tailles
 * passent d'ailleurs par une table indexée sur une prop, que rien ne permet de
 * lire depuis le gabarit.
 */
const primitives = [
    'Components/UI/GlassButton.vue',
    'Components/UI/GlassIconButton.vue',
    'Components/UI/GlassInput.vue',
    // Les trois portent leur cible dans une classe calculée, vérifiée par leurs propres tests.
    'Components/UI/GlassChip.vue',
    'Components/UI/GlassSegmented.vue',
    'Components/UI/GlassTile.vue',
]

const relatif = (fichier) => fichier.replace(jsRoot, 'resources/js')

const ligne = (source, index) => source.slice(0, index).split('\n').length

describe('les cibles tactiles', () => {
    /**
     * Une icône sans texte ne dit rien de sa propre taille : rien dans le HTML
     * n'oblige `p-1` à faire 44 px, et sept boutons de ce dépôt tombaient entre
     * 26 et 40. Sur un écran tactile, le geste rate — ou pire, attrape le
     * voisin, qui est parfois « supprimer ».
     *
     * La mesure est faite ici plutôt qu'à l'œil parce que c'est de
     * l'arithmétique, et que personne ne fait cette arithmétique en relisant une
     * diff.
     */
    it('donne 44 px à tout bouton réduit à une icône', () => {
        const trop_petits = collectSourceFiles({ skip: primitives }).flatMap((fichier) => {
            const source = readFileSync(fichier, 'utf8')

            return (
                [...source.matchAll(boutonsIcone)]
                    /*
                     * Trois formes portent une icône dans ce dépôt : `GlassIcon`,
                     * la ligature Material Symbols et le `<svg>` en ligne. Ne
                     * filtrer que sur la ligature laissait le `<svg>` hors de
                     * portée — c'est ainsi que treize boutons, dont neuf
                     * suppressions et un à 20 px sur l'écran de séance, tenaient
                     * sous un garde vert — et `GlassIcon` aussi, jusqu'à #2116.
                     */
                    .filter(
                        (trouve) =>
                            ICONE_DANS.test(trouve.groups.corps) && /^[a-z_]*$/.test(visibleText(trouve.groups.corps)),
                    )
                    .map((trouve) => ({
                        ou: `${relatif(fichier)}:${ligne(source, trouve.index)}`,
                        classes: [...trouve.groups.attributs.matchAll(/(?:class|:class)="([^"]*)"/gs)]
                            .map((c) => c[1])
                            .join(' '),
                        icone: icone(trouve.groups.corps),
                    }))
                    .filter((bouton) => mesurable(bouton.classes))
                    .map((bouton) => ({ ...bouton, px: cible(bouton.classes, bouton.icone) }))
                    .filter((bouton) => bouton.px < CIBLE)
                    .map((bouton) => `${bouton.ou} — ${bouton.px} px`)
            )
        })

        expect(trop_petits).toEqual([])
    })

    /**
     * Un lien réduit à une icône se touche comme un bouton (#2116). La garde ne
     * lisait que des `<button>` : le retour de l'en-tête mobile, sur les seize
     * pages qui en ont un, ceux de l'en-tête de bureau des outils, d'un exercice
     * et du jeûne tenaient en 40 px (`h-10 w-10`), et « Modifier » d'un modèle
     * en 36 (`p-2` autour d'une `GlassIcon size="sm"`, 20 px de large).
     */
    it('donne 44 px à tout lien réduit à une icône', () => {
        const trop_petits = collectSourceFiles({ skip: primitives }).flatMap((fichier) => {
            const source = readFileSync(fichier, 'utf8')

            return liensTropPetits(source).map(
                ({ attributs, px }) => `${relatif(fichier)}:${ligne(source, source.indexOf(attributs))} — ${px} px`,
            )
        })

        expect(trop_petits, 'size-11, ou min-h-touch min-w-touch, ou un before:-inset-* qui déborde').toEqual([])
    })

    it('mesure les liens comme les boutons, icône comprise', () => {
        const gabaritDe = (corps) => `<script setup></script>\n<template>\n<div>${corps}</div>\n</template>`
        const mesure = (corps) => liensTropPetits(gabaritDe(corps)).map(({ px }) => px)

        // Les défauts de #2116.
        expect(
            mesure('<Link href="/" class="flex h-10 w-10" aria-label="Retour"><GlassIcon name="arrow_back" /></Link>'),
        ).toEqual([40])
        expect(
            mesure(
                '<Link href="/" class="rounded-xl p-2" aria-label="Modifier"><GlassIcon name="edit" size="sm" /></Link>',
            ),
        ).toEqual([36])
        expect(mesure('<a href="/" aria-label="Aide"><svg class="size-4" /></a>')).toEqual([16])
        // `min-h-touch` ne donne que la hauteur, `min-w-touch` que la largeur :
        // l'autre dimension reste celle de l'icône et de son rembourrage.
        expect(
            mesure(
                '<Link href="/" class="min-h-touch" aria-label="Modifier"><GlassIcon name="edit" size="sm" /></Link>',
            ),
        ).toEqual([20])
        expect(
            mesure('<Link href="/" class="flex min-w-touch" aria-label="Aide"><GlassIcon name="help" /></Link>'),
        ).toEqual([24])
        expect(cible('min-h-touch px-2', 20)).toBe(36)
        expect(cible('min-w-touch py-1', 24)).toBe(32)

        expect(
            mesure('<Link href="/" class="flex size-11" aria-label="Retour"><GlassIcon name="arrow_back" /></Link>'),
        ).toEqual([])
        expect(
            mesure(
                '<Link href="/" class="min-h-touch px-3" aria-label="Série"><GlassIcon name="local_fire_department" /></Link>',
            ),
        ).toEqual([])
        expect(
            mesure(
                '<Link href="/" class="relative p-2 before:-inset-0.5" aria-label="Lire"><GlassIcon name="play_circle" /></Link>',
            ),
        ).toEqual([])
        expect(
            mesure(
                '<Link href="/" class="min-h-touch min-w-touch" aria-label="Modifier"><GlassIcon name="edit" size="sm" /></Link>',
            ),
        ).toEqual([])
        // Un lien qui porte un texte ne se mesure pas ici : sa largeur suit son libellé.
        expect(mesure('<Link href="/" class="p-1"><GlassIcon name="arrow_back" /> Retour</Link>')).toEqual([])
    })

    /**
     * Une largeur fixe ne tient pas dans une rangée flexible qui déborde
     * (#2116). Le retour de l'en-tête de bureau d'un exercice, `size-11` à côté
     * du nom, descendait à 26 px de large dès que le nom dépassait la rangée,
     * mesuré dans Chrome à 1 024 et à 1 280 px : un nom en admet 255. Son
     * jumeau de l'en-tête mobile portait `shrink-0`, pas lui.
     */
    it('garde 44 px de large à toute commande réduite à une icône dans une rangée flexible', () => {
        const retrecies = collectSourceFiles({ skip: primitives }).flatMap((fichier) => {
            const source = readFileSync(fichier, 'utf8')

            return commandesRetrecies(source).map(
                ({ attributs, px }) => `${relatif(fichier)}:${ligne(source, source.indexOf(attributs))} — ${px} px`,
            )
        })

        expect(retrecies, 'shrink-0 sur une commande de taille fixe posée dans une rangée flexible').toEqual([])
    })

    it('mesure ce qu’une rangée flexible laisse à une commande de taille fixe', () => {
        const gabaritDe = (corps) => `<script setup></script>\n<template>\n${corps}\n</template>`
        const mesure = (corps) => commandesRetrecies(gabaritDe(corps)).map(({ px }) => px)
        const retour = (classes) =>
            `<Link href="/" class="${classes}" aria-label="Retour"><GlassIcon name="arrow_back" /></Link>`

        // Le défaut de #2116 : il ne garde que sa ligature de 24 px.
        expect(
            mesure(`<div class="flex items-center gap-4">${retour('flex size-11')}<h1>Développé couché</h1></div>`),
        ).toEqual([24])
        expect(mesure(`<div class="bureau:flex hidden">${retour('flex size-11')}</div>`)).toEqual([24])
        expect(
            mesure(
                '<div class="flex"><button type="button" class="flex h-11 w-11" aria-label="Pause"><svg class="h-5 w-5" /></button></div>',
            ),
        ).toEqual([20])

        expect(mesure(`<div class="flex items-center gap-4">${retour('flex size-11 shrink-0')}</div>`)).toEqual([])
        expect(mesure(`<div class="flex">${retour('flex size-11 flex-none')}</div>`)).toEqual([])
        expect(mesure(`<div class="flex">${retour('min-h-touch min-w-touch')}</div>`)).toEqual([])
        // Une rangée qui passe à la ligne, une colonne, un bloc ne pressent pas la largeur.
        expect(mesure(`<div class="flex flex-wrap gap-2">${retour('flex size-11')}</div>`)).toEqual([])
        expect(mesure(`<div class="flex flex-col">${retour('flex size-11')}</div>`)).toEqual([])
        expect(mesure(`<div class="space-y-2">${retour('flex size-11')}</div>`)).toEqual([])
        expect(seRetrecit('flex size-11', 'flex flex-col sm:flex-row')).toBe(true)
    })
})

/**
 * Les commandes réduites à une icône dont une rangée flexible ramène la
 * largeur sous 44 px : leur parent dans le gabarit est une rangée, et rien ne
 * tient leur largeur fixe.
 *
 * @param {string} source
 * @returns {{attributs: string, px: number}[]}
 */
const commandesRetrecies = (source) =>
    elementsDuGabarit(gabarit(source))
        .elements.filter(({ nom, contenu }) => ['Link', 'a', 'button'].includes(nom) && contenu !== null)
        .filter(({ contenu }) => !/<(?:button|GlassButton|GlassIconButton)\b/.test(contenu))
        .filter(({ contenu }) => ICONE_DANS.test(contenu) && /^[a-z_]*$/.test(visibleText(contenu)))
        .filter(({ liste, parent }) => mesurable(liste) && seRetrecit(liste, parent?.liste ?? ''))
        .map(({ attributs, liste, contenu, parent }) => ({
            attributs,
            px: cible(liste, icone(contenu), parent?.liste ?? ''),
        }))
        .filter(({ px }) => px < CIBLE)

/**
 * Les liens d'Inertia et les liens HTML réduits à une icône, sous 44 px.
 *
 * @param {string} source
 * @returns {{attributs: string, px: number}[]}
 */
const liensTropPetits = (source) =>
    elementsDuGabarit(gabarit(source))
        .elements.filter(({ nom, contenu }) => ['Link', 'a'].includes(nom) && contenu !== null)
        // Un lien qui enveloppe un bouton n'est pas réduit à une icône : le bouton
        // porte la cible, et l'imbrication relève de liensSansBouton.test.js (#2117).
        .filter(({ contenu }) => !/<(?:button|GlassButton|GlassIconButton)\b/.test(contenu))
        .filter(({ contenu }) => ICONE_DANS.test(contenu) && /^[a-z_]*$/.test(visibleText(contenu)))
        .filter(({ liste }) => mesurable(liste))
        .map(({ attributs, liste, contenu }) => ({ attributs, px: cible(liste, icone(contenu)) }))
        .filter(({ px }) => px < CIBLE)

import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'

import { collectSourceFiles, jsRoot } from './sourceFiles'

/**
 * Un placeholder n'est pas un nom : il s'efface dès que le champ est rempli, et
 * un champ prérempli n'en montre jamais. Les séries d'un modèle se lisaient
 * « réps », « kg », « réps », « kg »… sans dire laquelle de quel exercice, et la
 * modification d'un complément ouvrait trois boîtes sans nom (#1972).
 *
 * Chaque champ se nomme donc par l'un de ces moyens :
 * - `label` sur le composant (avec `hide-label` si la place manque) ;
 * - `aria-label` ou `aria-labelledby`, qui distingue chaque rangée
 *   (« Répétitions, série 1, Squat ») ;
 * - un `<label>` qui l'enveloppe : une case et son texte, le texte la coche ;
 * - un `<label for>` qui vise son `id`.
 */
const CHAMPS = [
    'input',
    'select',
    'textarea',
    'GlassInput',
    'GlassSelect',
    'GlassTextarea',
    'GlassBigNumber',
    'Checkbox',
]

/**
 * Les composants qui posent eux-mêmes l'étiquette de leur champ, à partir de la
 * prop `label` ou des attributs reçus : c'est à leurs appels d'être nommés, et
 * la garde les lit.
 */
const PRIMITIVES = [
    'Components/Form/Checkbox.vue',
    'Components/UI/GlassBigNumber.vue',
    'Components/UI/GlassInput.vue',
    'Components/UI/GlassSelect.vue',
    'Components/UI/GlassTextarea.vue',
]

const baliseDeChamp = new RegExp(`<(${CHAMPS.join('|')})\\b((?:[^>"']|"[^"]*"|'[^']*')*)>`, 'g')

const NOMME = /(?:^|\s)(?:label="[^"]*\S[^"]*"|:label="[^"]+"|:?aria-label(?:ledby)?="[^"]*\S[^"]*")/

/**
 * Les champs d'un composant monofichier qu'aucune étiquette ne nomme.
 *
 * @param {string} source
 * @returns {string[]} La balise de chaque champ sans nom, tronquée.
 */
export const champsSansNom = (source) => {
    const debut = source.indexOf('<template')
    const gabarit = debut === -1 ? '' : source.slice(debut)
    const ciblesDesLibelles = new Set([...gabarit.matchAll(/<label\b[^>]*\sfor="([^"]+)"/g)].map(([, id]) => id))

    return [...gabarit.matchAll(baliseDeChamp)]
        .filter(({ 2: attributs, index }) => {
            if (NOMME.test(attributs) || /(?:^|\s)type="hidden"/.test(attributs)) {
                return false
            }

            const id = /(?:^|\s)id="([^"]+)"/.exec(attributs)
            if (id !== null && ciblesDesLibelles.has(id[1])) {
                return false
            }

            // Enveloppé dans un <label> encore ouvert à cet endroit du gabarit.
            const avant = gabarit.slice(0, index)
            const ouverts = [...avant.matchAll(/<label\b/g)].length
            const fermes = [...avant.matchAll(/<\/label\s*>/g)].length

            return ouverts <= fermes
        })
        .map(([balise]) => balise.replace(/\s+/g, ' ').slice(0, 100))
}

describe('chaque champ a un nom qui survit à sa saisie', () => {
    it('nomme chaque champ de chaque page et de chaque composant', () => {
        const fautifs = collectSourceFiles({ skip: PRIMITIVES }).flatMap((chemin) =>
            champsSansNom(readFileSync(chemin, 'utf8')).map(
                (balise) => `${chemin.replace(jsRoot, 'resources/js')} : ${balise}`,
            ),
        )

        expect(fautifs, 'passer label (et hide-label), aria-label, ou envelopper le champ dans son <label>').toEqual([])
    })

    it('ne laisse passer qu’un champ nommé', () => {
        const gabarit = (corps) => `<script setup></script>\n<template>\n<div>${corps}</div>\n</template>`

        expect(champsSansNom(gabarit('<input v-model="a" placeholder="réps" />'))).toHaveLength(1)
        expect(champsSansNom(gabarit('<GlassInput v-model="a" placeholder="Nom" />'))).toHaveLength(1)
        expect(champsSansNom(gabarit('<GlassInput v-model="a" label="" />'))).toHaveLength(1)
        expect(champsSansNom(gabarit('<Checkbox v-model:checked="a" /><label>Envoyer</label>'))).toHaveLength(1)
        expect(champsSansNom(gabarit('<label>Nom</label><input id="nom" />'))).toHaveLength(1)

        expect(champsSansNom(gabarit('<GlassInput v-model="a" label="Nom" hide-label />'))).toEqual([])
        expect(champsSansNom(gabarit('<input :aria-label="`Répétitions, série ${i}`" />'))).toEqual([])
        expect(champsSansNom(gabarit('<input aria-labelledby="titre" />'))).toEqual([])
        expect(champsSansNom(gabarit('<label class="flex"><Checkbox /><span>Lun</span></label>'))).toEqual([])
        expect(champsSansNom(gabarit('<label for="nom">Nom</label\n><GlassInput id="nom" />'))).toEqual([])
        expect(champsSansNom(gabarit('<input type="hidden" name="jeton" />'))).toEqual([])
    })
})

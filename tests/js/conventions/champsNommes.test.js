import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { mount } from '@vue/test-utils'

import Checkbox from '@/Components/Form/Checkbox.vue'
import GlassBigNumber from '@/Components/UI/GlassBigNumber.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassSelect from '@/Components/UI/GlassSelect.vue'
import GlassTextarea from '@/Components/UI/GlassTextarea.vue'
import { collectSourceFiles, jsRoot } from './sourceFiles'

/**
 * Un placeholder n'est pas un nom : il s'efface dès que le champ est rempli, et
 * un champ prérempli n'en montre jamais. Les séries d'un modèle se lisaient
 * « réps », « kg », « réps », « kg »… sans dire laquelle de quel exercice, et la
 * modification d'un complément ouvrait trois boîtes sans nom (#1972).
 *
 * Chaque champ se nomme donc par l'un de ces moyens :
 * - `label` : la prop d'un composant `Glass*` (avec `hide-label` si la place
 *   manque) ;
 * - `aria` : `aria-label` ou `aria-labelledby`, qui distingue chaque rangée
 *   (« Répétitions, série 1, Squat ») ;
 * - `for` : un `<label for>` qui vise son `id` ;
 * - `enveloppe` : un `<label>` qui l'enveloppe, comme une case et son texte, que
 *   le texte coche.
 *
 * Un moyen ne compte que là où il atteint le contrôle. Un attribut `label` sur
 * un `<input>` brut ne nomme rien. `GlassTextarea` et `Checkbox` laissent leurs
 * attributs sur leur div racine : un `aria-label` ou un `id` posé sur eux n'y
 * arrive jamais. `GlassInput`, `GlassSelect` et `GlassBigNumber`, eux, les
 * transmettent au contrôle.
 *
 * @type {Record<string, Array<'label' | 'aria' | 'for' | 'enveloppe'>>}
 */
const MOYENS_QUI_NOMMENT = {
    input: ['aria', 'for', 'enveloppe'],
    select: ['aria', 'for', 'enveloppe'],
    textarea: ['aria', 'for', 'enveloppe'],
    GlassInput: ['label', 'aria', 'for', 'enveloppe'],
    GlassSelect: ['label', 'aria', 'for', 'enveloppe'],
    GlassBigNumber: ['label', 'aria', 'for', 'enveloppe'],
    GlassTextarea: ['label'],
    Checkbox: ['enveloppe'],
}

const CHAMPS = Object.keys(MOYENS_QUI_NOMMENT)

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

const PROP_LABEL = /(?:^|\s)(?:label="[^"]*\S[^"]*"|:label="[^"]+")/

const ATTRIBUT_ARIA = /(?:^|\s):?aria-label(?:ledby)?="[^"]*\S[^"]*"/

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
        .filter(({ 1: champ, 2: attributs, index }) => {
            if (/(?:^|\s)type="hidden"/.test(attributs)) {
                return false
            }

            const moyens = MOYENS_QUI_NOMMENT[champ]

            if (moyens.includes('label') && PROP_LABEL.test(attributs)) {
                return false
            }

            if (moyens.includes('aria') && ATTRIBUT_ARIA.test(attributs)) {
                return false
            }

            const id = /(?:^|\s)id="([^"]+)"/.exec(attributs)
            if (moyens.includes('for') && id !== null && ciblesDesLibelles.has(id[1])) {
                return false
            }

            if (!moyens.includes('enveloppe')) {
                return true
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
        // Des attributs qui n'atteignent pas le contrôle.
        expect(champsSansNom(gabarit('<input v-model="a" label="Nom" />'))).toHaveLength(1)
        expect(champsSansNom(gabarit('<Checkbox v-model:checked="a" aria-label="Lundi" />'))).toHaveLength(1)
        expect(champsSansNom(gabarit('<label for="lun">Lundi</label><Checkbox id="lun" />'))).toHaveLength(1)
        expect(champsSansNom(gabarit('<GlassTextarea v-model="a" aria-label="Notes" />'))).toHaveLength(1)
        expect(champsSansNom(gabarit('<label for="notes">Notes</label><GlassTextarea id="notes" />'))).toHaveLength(1)

        expect(champsSansNom(gabarit('<GlassInput v-model="a" label="Nom" hide-label />'))).toEqual([])
        expect(champsSansNom(gabarit('<input :aria-label="`Répétitions, série ${i}`" />'))).toEqual([])
        expect(champsSansNom(gabarit('<input aria-labelledby="titre" />'))).toEqual([])
        expect(champsSansNom(gabarit('<label class="flex"><Checkbox /><span>Lun</span></label>'))).toEqual([])
        expect(champsSansNom(gabarit('<label for="nom">Nom</label\n><GlassInput id="nom" />'))).toEqual([])
        expect(champsSansNom(gabarit('<GlassSelect v-model="a" aria-label="Unité" />'))).toEqual([])
        expect(champsSansNom(gabarit('<GlassTextarea v-model="a" label="Notes" />'))).toEqual([])
        expect(champsSansNom(gabarit('<input type="hidden" name="jeton" />'))).toEqual([])
    })

    /**
     * La table des moyens décrit ce que chaque composant fait de ses attributs.
     * Le jour où `Checkbox` transmettra les siens à sa case, ce test tombe, et
     * la table s'ouvre à `aria` et `for` en connaissance de cause.
     */
    it.each([
        ['Checkbox', Checkbox, {}],
        ['GlassBigNumber', GlassBigNumber, { label: 'Charge' }],
        ['GlassInput', GlassInput, {}],
        ['GlassSelect', GlassSelect, {}],
        ['GlassTextarea', GlassTextarea, { label: 'Notes' }],
    ])('%s : la table ne retient que les attributs qui atteignent le contrôle', (nom, composant, props) => {
        const wrapper = mount(composant, { props, attrs: { 'aria-label': 'Nom donné', id: 'champ-vise' } })
        const controle = wrapper.get('input, select, textarea').element

        expect({
            aria: controle.getAttribute('aria-label') === 'Nom donné',
            for: controle.id === 'champ-vise',
        }).toEqual({
            aria: MOYENS_QUI_NOMMENT[nom].includes('aria'),
            for: MOYENS_QUI_NOMMENT[nom].includes('for'),
        })
    })
})

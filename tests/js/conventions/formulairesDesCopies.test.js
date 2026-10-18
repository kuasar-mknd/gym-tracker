import { describe, expect, it } from 'vitest'
import { existsSync, readFileSync, statSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'

import { jsRoot } from './sourceFiles'
import { PAGES_GARDEES } from '@/Utils/pagesGardees'

/**
 * Un formulaire qu'une page gardée remplit depuis ses props se recale quand
 * la page cesse d'être une copie hors ligne (#2048, partie E).
 *
 * Au retour du réseau, une copie se recharge en entier par `router.reload`,
 * qui garde l'état de la page : un `useForm` rempli au montage gardait les
 * valeurs de la copie, et « Enregistrer » les renvoyait au serveur, alors que
 * la page montrait celles du serveur. Chaque `useForm` qu'atteint une page de
 * la liste blanche (la page, ses composants et ses composables, imports à la
 * demande compris), et dont les valeurs ne sont pas toutes écrites en dur,
 * passe donc par `recalerApresLaCopie` dans le même fichier
 * (`resources/js/composables/useCopieHorsLigne.js`).
 */

/** Les modules qu'un fichier importe, en tête ou à la demande, résolus dans resources/js. */
const importsDe = (chemin, source) =>
    [...source.matchAll(/(?:\bfrom\s+|\bimport\s*\(\s*)['"]([^'"]+)['"]/g)].flatMap(([, specification]) => {
        const base = specification.startsWith('@/')
            ? join(jsRoot, specification.slice(2))
            : specification.startsWith('.')
              ? resolve(dirname(chemin), specification)
              : null

        if (base === null) {
            return []
        }

        const trouve = [base, `${base}.js`, `${base}.vue`, join(base, 'index.js')].find(
            (candidat) => existsSync(candidat) && statSync(candidat).isFile(),
        )

        return trouve === undefined ? [] : [trouve]
    })

/** Les fichiers qu'atteint une page gardée, la page comprise. */
const fichiersDesPagesGardees = () => {
    const vus = new Set()
    const aVoir = PAGES_GARDEES.map(({ composant }) => join(jsRoot, 'Pages', `${composant}.vue`))

    while (aVoir.length > 0) {
        const chemin = aVoir.pop()

        if (vus.has(chemin)) {
            continue
        }

        vus.add(chemin)
        aVoir.push(...importsDe(chemin, readFileSync(chemin, 'utf8')))
    }

    return [...vus]
}

/** Le source sans ses commentaires : un `useForm(` cité en commentaire n'en est pas un. */
const sansCommentaires = (source) =>
    source
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/(^|[^:'"`\\])\/\/.*$/gm, '$1')

/** Le texte entre la parenthèse ouvrante à `debut` et celle qui la ferme, chaînes comprises. */
const argumentsDeLAppel = (source, debut) => {
    let profondeur = 0
    let chaine = null

    for (let index = debut; index < source.length; index++) {
        const caractere = source[index]

        if (chaine !== null) {
            if (caractere === '\\') {
                index++
            } else if (caractere === chaine) {
                chaine = null
            }

            continue
        }

        if (caractere === "'" || caractere === '"' || caractere === '`') {
            chaine = caractere
        } else if (caractere === '(') {
            profondeur++
        } else if (caractere === ')' && --profondeur === 0) {
            return source.slice(debut + 1, index)
        }
    }

    return source.slice(debut + 1)
}

/** Si les valeurs passées à `useForm` lisent autre chose que des littéraux : les props, une copie locale. */
const lisAutreChoseQueDesLitteraux = (texte) =>
    texte
        .replace(/'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"|`(?:\\.|[^`\\])*`/g, '')
        .replace(/[A-Za-z_$][\w$]*\s*:/g, '')
        .match(/[A-Za-z_$][\w$]*/g)
        ?.some((mot) => !['true', 'false', 'null', 'undefined'].includes(mot)) ?? false

/** Chaque `useForm` des pages gardées dont les valeurs ne sont pas toutes écrites en dur. */
const formulairesRemplisDepuisLaPage = () =>
    fichiersDesPagesGardees().flatMap((chemin) => {
        const source = sansCommentaires(readFileSync(chemin, 'utf8'))
        const fichier = chemin.replace(jsRoot, 'resources/js')

        return [...source.matchAll(/(?:\b(?:const|let)\s+([\w$]+)\s*=\s*)?\buseForm\s*\(/g)]
            .filter((appel) =>
                lisAutreChoseQueDesLitteraux(argumentsDeLAppel(source, appel.index + appel[0].length - 1)),
            )
            .map(([, nom]) => ({
                formulaire: `${fichier} ${nom ?? '(sans nom)'}`,
                recale: nom !== undefined && new RegExp(`\\brecalerApresLaCopie\\(\\s*${nom}\\s*,`).test(source),
            }))
    })

describe('un formulaire d’une page gardée rempli depuis la page se recale à la sortie de la copie', () => {
    it('lit les pages gardées et ce qu’elles importent', () => {
        const fichiers = fichiersDesPagesGardees().map((chemin) => chemin.replace(jsRoot, 'resources/js'))

        expect(fichiers).toEqual(
            expect.arrayContaining([
                'resources/js/Pages/Tools/WarmupCalculator.vue',
                'resources/js/Pages/Workouts/Show.vue',
                'resources/js/composables/useReglagesDeLaSeance.js',
                'resources/js/Components/Exercises/NouvelExerciceModal.vue',
            ]),
        )
    })

    it('trouve ceux que la page remplit, et pas ceux écrits en dur', () => {
        const formulaires = formulairesRemplisDepuisLaPage().map(({ formulaire }) => formulaire)

        expect(formulaires).toEqual(
            expect.arrayContaining([
                'resources/js/Pages/Tools/WarmupCalculator.vue form',
                'resources/js/composables/useReglagesDeLaSeance.js settingsForm',
            ]),
        )
        expect(formulaires).not.toContain('resources/js/Pages/Tools/IntervalTimer.vue form')
        expect(formulaires).not.toContain('resources/js/Pages/Tools/PlateCalculator.vue newPlate')
    })

    it('les recale tous par `recalerApresLaCopie`', () => {
        const sansRecalage = formulairesRemplisDepuisLaPage()
            .filter(({ recale }) => !recale)
            .map(({ formulaire }) => formulaire)

        expect(sansRecalage).toEqual([])
    })
})

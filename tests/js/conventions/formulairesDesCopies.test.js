import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { importsDe, jsRoot } from './sourceFiles'
import { sansCommentairesHtml } from './gabarits'
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
 *
 * Un formulaire rempli après coup (`form.name = timer.name`) l'est tout
 * autant : « Modifier le minuteur », ouvert sur la copie du minuteur
 * d'intervalles, en gardait les valeurs au retour du réseau, et « Mettre à
 * jour » les renvoyait (seconde relecture de la partie E, 2026-10-18). Chaque
 * formulaire qui reçoit autre chose qu'un littéral se recale aussi, ou se
 * nomme dans `REMPLIS_SANS_RECALAGE`, avec la raison qui empêche une valeur
 * de la copie d'y entrer et le cas qui la prouve.
 *
 * Même famille pour ce qu'une page gardée recopie de ses props dans un `ref`
 * (une liste qu'elle modifie avant la réponse) : la page n'étant pas remontée,
 * la copie locale de la bibliothèque de la séance restait celle de la copie
 * hors ligne. Chacune suit sa prop par un `watch`.
 */

/**
 * Les formulaires des pages gardées remplis après coup d'autre chose que de
 * littéraux, sans `recalerApresLaCopie` : pourquoi aucune valeur d'une copie
 * n'y entre, et le cas (fichier, titre) qui le prouve.
 *
 * @type {Array<{ formulaire: string, raison: string, preuve: [string, string] }>}
 */
const REMPLIS_SANS_RECALAGE = [
    {
        formulaire: 'resources/js/Pages/Tools/IntervalTimer.vue form',
        raison: '« Modifier le minuteur », qui le remplit depuis un préréglage, est désactivé sur une copie',
        preuve: [
            'tests/js/pages/minuteurServiDUneCopieAvecInertia.test.js',
            'un préréglage ne s’ouvre pas en modification sur la copie',
        ],
    },
    {
        formulaire: 'resources/js/Pages/Exercises/Index.vue editForm',
        raison: 'modifier un exercice est désactivé sur une copie : ses boutons, et le glissement de sa rangée',
        preuve: [
            'tests/js/components/sharedComponents.test.js',
            'ne propose ni de modifier ni de supprimer, et dit pourquoi',
        ],
    },
    {
        formulaire: 'resources/js/Components/Workout/AjoutDExerciceModal.vue createExerciseForm',
        raison: 'le nom vient de la recherche que la personne a tapée, jamais d’une prop',
        preuve: [
            'tests/js/components/ajoutDExerciceModal.test.js',
            'propose de créer ce que la recherche ne trouve pas, avec ce nom pré-rempli',
        ],
    },
]

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

/**
 * Le source sans ses commentaires : un `useForm(` cité en commentaire n'en est pas un.
 * Les commentaires HTML partent par `sansCommentairesHtml` (gabarits.js), que CodeQL
 * ne prend pas pour une désinfection incomplète.
 */
const sansCommentaires = (source) =>
    sansCommentairesHtml(source.replace(/\/\*[\s\S]*?\*\//g, '')).replace(/(^|[^:'"`\\])\/\/.*$/gm, '$1')

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

/** Chaque `useForm` des pages gardées : son fichier, son nom et ce qui le remplit. */
const formulairesDesPagesGardees = () =>
    fichiersDesPagesGardees().flatMap((chemin) => {
        const source = sansCommentaires(readFileSync(chemin, 'utf8'))
        const fichier = chemin.replace(jsRoot, 'resources/js')

        return [...source.matchAll(/(?:\b(?:const|let)\s+([\w$]+)\s*=\s*)?\buseForm\s*\(/g)].map((appel) => {
            const nom = appel[1]

            return {
                formulaire: `${fichier} ${nom ?? '(sans nom)'}`,
                remplisAuMontage: lisAutreChoseQueDesLitteraux(
                    argumentsDeLAppel(source, appel.index + appel[0].length - 1),
                ),
                remplisApresCoup: nom === undefined ? [] : affectationsApresCoup(source, nom),
                recale: nom !== undefined && new RegExp(`\\brecalerApresLaCopie\\(\\s*${nom}\\s*,`).test(source),
            }
        })
    })

/**
 * Ce qu'un fichier affecte aux champs d'un formulaire après sa création, quand
 * la valeur lit autre chose que des littéraux (`form.name = timer.name`).
 *
 * @param {string} source
 * @param {string} nom
 * @returns {string[]}
 */
const affectationsApresCoup = (source, nom) =>
    [...source.matchAll(new RegExp(`\\b${nom}\\.([\\w$]+)\\s*=(?![=>])\\s*([^\\n;]*)`, 'g'))]
        .filter(([, , valeur]) => lisAutreChoseQueDesLitteraux(valeur))
        .map(([, champ, valeur]) => `${champ} = ${valeur.trim()}`)

/** Chaque `useForm` des pages gardées dont les valeurs ne sont pas toutes écrites en dur. */
const formulairesRemplisDepuisLaPage = () =>
    formulairesDesPagesGardees().filter(({ remplisAuMontage }) => remplisAuMontage)

/** Chaque `useForm` des pages gardées qui reçoit, après sa création, autre chose qu'un littéral. */
const formulairesRemplisApresCoup = () =>
    formulairesDesPagesGardees().filter(({ remplisApresCoup }) => remplisApresCoup.length > 0)

/** Les arguments d'un appel, découpés aux virgules de premier niveau. */
const argumentsSepares = (texte) => {
    const morceaux = []
    let profondeur = 0
    let chaine = null
    let debut = 0

    for (let index = 0; index < texte.length; index++) {
        const caractere = texte[index]

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
        } else if ('([{'.includes(caractere)) {
            profondeur++
        } else if (')]}'.includes(caractere)) {
            profondeur--
        } else if (caractere === ',' && profondeur === 0) {
            morceaux.push(texte.slice(debut, index))
            debut = index + 1
        }
    }

    return [...morceaux, texte.slice(debut)]
}

/**
 * Ce que chaque page gardée recopie de ses props dans un état à elle
 * (`ref`, `shallowRef`, `reactive`), et si un `watch` du même fichier suit
 * chacune de ces props.
 */
const copiesLocalesDesProps = () =>
    PAGES_GARDEES.flatMap(({ composant }) => {
        const chemin = join(jsRoot, 'Pages', `${composant}.vue`)
        const source = sansCommentaires(readFileSync(chemin, 'utf8'))
        const fichier = chemin.replace(jsRoot, 'resources/js')
        const suivies = [...source.matchAll(/\bwatch\s*\(/g)].flatMap((appel) => {
            const [ceQueSuitLeWatch] = argumentsSepares(argumentsDeLAppel(source, appel.index + appel[0].length - 1))

            return [...ceQueSuitLeWatch.matchAll(/\bprops\.([\w$]+)/g)].map(([, prop]) => prop)
        })

        return [...source.matchAll(/\b(?:const|let)\s+([\w$]+)\s*=\s*(?:ref|shallowRef|reactive)\s*\(/g)].flatMap(
            (appel) =>
                [...argumentsDeLAppel(source, appel.index + appel[0].length - 1).matchAll(/\bprops\.([\w$]+)/g)].map(
                    ([, prop]) => ({ copie: `${fichier} ${appel[1]} (props.${prop})`, suivie: suivies.includes(prop) }),
                ),
        )
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

    it('trouve ceux que la page remplit après coup, et pas ceux qu’elle remet à des littéraux', () => {
        const formulaires = formulairesRemplisApresCoup()

        expect(formulaires.map(({ formulaire }) => formulaire)).toEqual(
            expect.arrayContaining([
                'resources/js/Pages/Tools/IntervalTimer.vue form',
                'resources/js/Pages/Exercises/Index.vue editForm',
            ]),
        )
        // `resetForm` remet des littéraux ; `editTimer` copie le préréglage.
        expect(
            formulaires.find(({ formulaire }) => formulaire.endsWith('IntervalTimer.vue form')).remplisApresCoup,
        ).toEqual([
            'id = timer.id',
            'name = timer.name',
            'work_seconds = timer.work_seconds',
            'rest_seconds = timer.rest_seconds',
            'rounds = timer.rounds',
            'warmup_seconds = timer.warmup_seconds',
        ])
        expect(affectationsApresCoup('form.processing = true\nform.id = null\nform.nom = "x"', 'form')).toEqual([])
        expect(affectationsApresCoup('if (form.id === timer.id) {}', 'form')).toEqual([])
    })

    it('les recale aussi, ou dit pourquoi aucune valeur de la copie n’y entre', () => {
        const admis = new Set(REMPLIS_SANS_RECALAGE.map(({ formulaire }) => formulaire))
        const formulaires = formulairesRemplisApresCoup()
        const sansRaison = formulaires
            .filter(({ recale, formulaire }) => !recale && !admis.has(formulaire))
            .map(({ formulaire, remplisApresCoup }) => `${formulaire} : ${remplisApresCoup.join(', ')}`)

        expect(sansRaison).toEqual([])

        // Une raison qui ne vaut plus pour rien, ou que rien ne prouve, n'en est pas une.
        const remplis = new Set(formulaires.filter(({ recale }) => !recale).map(({ formulaire }) => formulaire))

        for (const { formulaire, preuve } of REMPLIS_SANS_RECALAGE) {
            const [fichier, cas] = preuve

            expect(remplis.has(formulaire), formulaire).toBe(true)
            expect(readFileSync(join(jsRoot, '..', '..', fichier), 'utf8'), `${fichier} : ${cas}`).toContain(cas)
        }
    })
})

describe('ce qu’une page gardée recopie de ses props suit la prop', () => {
    it('trouve les copies locales des props', () => {
        expect(copiesLocalesDesProps().map(({ copie }) => copie)).toEqual(
            expect.arrayContaining([
                'resources/js/Pages/Workouts/Show.vue localExercises (props.exercises)',
                'resources/js/Pages/Exercises/Index.vue localExercises (props.exercises)',
                'resources/js/Pages/Workouts/Index.vue workoutList (props.workouts)',
            ]),
        )
    })

    it('chacune suit sa prop par un `watch` du même fichier', () => {
        const figees = copiesLocalesDesProps()
            .filter(({ suivie }) => !suivie)
            .map(({ copie }) => copie)

        expect(figees).toEqual([])
    })
})

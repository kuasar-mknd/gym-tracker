import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { babelParse, parse as lireLeComposant, walk } from 'vue/compiler-sfc'
import { collectSourceFiles, jsRoot } from './sourceFiles'

/**
 * Une mise à jour optimiste revient sur tout échec (#2077).
 *
 * Les séances et la bibliothèque retiraient une ligne avant la réponse et ne
 * la remettaient que dans `onError`, qu'Inertia n'appelle que sur des erreurs
 * de validation : sans réseau, sur un 403, un 419 ou un 500, sur une visite
 * annulée ou interrompue, la ligne restait partie alors que rien n'était
 * supprimé. Le calendrier, les statistiques et le repos automatique avaient le
 * même défaut.
 *
 * La garde lit chaque visite d'Inertia de `resources/js` (`router.post`,
 * `put`, `patch`, `delete`, `visit`, `get`, `reload`, et les mêmes sur un
 * formulaire de `useForm`) et ce que la fonction qui la lance change AVANT
 * elle : une affectation sous un objet (`liste.value = …`, `etat.champ = …`),
 * un `++` ou un `--` sur un champ, ou un appel qui modifie en place (`splice`,
 * `push`, `unshift`, `add`, `delete`…). Une visite précédée d'un tel
 * changement passe ses options par `revenirSiLaVisiteEchoue`
 * (`resources/js/Utils/miseAJourOptimiste.js`), posé à la place des options
 * (`router.get(url, données, options)`, comme `post`), ou par l'option
 * `optimistic` d'Inertia, une fonction, dans les options ou en chaîne
 * (`router.optimistic(fonction).delete(…)`). Deux changements n'en sont pas :
 * les champs du formulaire de `useForm` qui lance la visite, qu'elle envoie,
 * et le chemin même qu'une affectation du `onFinish` de la même visite
 * rétablit, ou ce qui est dessous (`etat.occupe` rétabli couvre
 * `etat.occupe`, pas `etat.choix`) : un bouton occupé, qu'Inertia libère à
 * toute issue.
 *
 * Ses limites. Elle ne suit pas les appels : un changement fait dans une
 * fonction appelée avant la visite lui échappe, comme un changement fait par
 * une affectation simple (`compteur = 1`) ou par une variable qui recopie
 * l'état sous un autre nom. Une option `optimistic` nommée passe si le
 * fichier déclare ce nom comme une fonction, sans regarder ce qu'elle rend.
 * Un indice calculé (`liste[i]`) ne se rétablit que par un chemin plus court
 * (`liste = …`). Elle ne lit que les visites d'Inertia : les écritures de la
 * séance passent par `SyncService`, dont le `catch` défait le changement hors
 * file (`.ai/rules/js.md`). Elle ne juge pas ce que fait `revenir` :
 * `tests/js/pages/misesAJourOptimistesAvecInertia.test.js` joue chaque échec
 * sur les pages. Ce qui n'est pas une mise à jour optimiste et qu'elle
 * prendrait pour une va dans `EXEMPTIONS`, avec sa raison ; une exemption
 * devenue inutile la fait échouer.
 */

const VISITES = new Set(['post', 'put', 'patch', 'delete', 'visit', 'get', 'reload', 'submit'])

/**
 * La place des options parmi les arguments, selon l'appel : celle de la
 * version installée (`get(url, data = {}, options = {})` comme `post`).
 */
const PLACE_DES_OPTIONS_DU_ROUTEUR = { post: 2, put: 2, patch: 2, delete: 1, visit: 1, get: 2, reload: 0 }
const PLACE_DES_OPTIONS_DU_FORMULAIRE = { post: 1, put: 1, patch: 1, delete: 1, get: 1, submit: 2 }

const MUTATEURS = new Set([
    'splice',
    'push',
    'unshift',
    'pop',
    'shift',
    'sort',
    'reverse',
    'fill',
    'copyWithin',
    'add',
    'set',
    'delete',
    'clear',
])

/** Ce qui n'est pas l'état d'une page, même modifié en place. */
const HORS_DE_L_ETAT = new Set([
    'router',
    'SyncService',
    'window',
    'document',
    'localStorage',
    'sessionStorage',
    'navigator',
    'history',
    'console',
])

/**
 * Ce que la garde prendrait pour une mise à jour optimiste et qui n'en est
 * pas une : le fichier, l'état changé avant la visite, et pourquoi il n'a pas
 * à revenir.
 */
const EXEMPTIONS = [
    {
        fichier: 'resources/js/Components/Achievements/CelebrationModal.vue',
        etat: 'badgesFermes',
        raison: 'la personne a fermé la carte : la rouvrir parce que sa lecture n’a pas abouti serait pire, et la notification restée non lue revient au prochain chargement',
    },
    {
        fichier: 'resources/js/Pages/Profile/Partials/DeleteUserForm.vue',
        etat: 'suppressionSansReseau',
        raison: 'le message d’une suppression précédente restée sans réponse, effacé au départ de la suivante : la visite pose le sien',
    },
    {
        fichier: 'resources/js/Pages/Tools/WarmupCalculator.vue',
        etat: 'saveError',
        raison: 'le message d’un enregistrement précédent refusé, effacé au départ du suivant : la visite pose le sien',
    },
    {
        fichier: 'resources/js/composables/useReglagesDeLaSeance.js',
        etat: 'clotureSansReseau',
        raison: 'le message d’une clôture précédente, effacé au départ de la suivante : la visite pose le sien',
    },
    {
        fichier: 'resources/js/composables/useReglagesDeLaSeance.js',
        etat: 'ecrituresEnAttente',
        raison: 'le compte des écritures encore en file, relevé avant la clôture : il dit ce qui est, pas ce que le serveur fera',
    },
]

/** Le nom de l'objet au bout d'une chaîne `a.b.c`, `a[0].b` ou `f().b`. */
const racineDe = (noeud) => {
    let courant = noeud

    while (courant) {
        if (courant.type === 'Identifier') {
            return courant.name
        }

        if (courant.type === 'ThisExpression') {
            return 'this'
        }

        if (courant.type === 'MemberExpression' || courant.type === 'OptionalMemberExpression') {
            courant = courant.object
        } else if (courant.type === 'CallExpression' || courant.type === 'OptionalCallExpression') {
            courant = courant.callee
        } else if (courant.type === 'TSNonNullExpression' || courant.type === 'ParenthesizedExpression') {
            courant = courant.expression
        } else {
            return null
        }
    }

    return null
}

const nomDeLaPropriete = (membre) =>
    !membre.computed && membre.property.type === 'Identifier' ? membre.property.name : null

/** Un indice calculé, qu'aucun autre ne vaut : `liste[i]` et `liste[j]` ne se confondent pas. */
const INDICE_CALCULE = '[?]'

/**
 * Le chemin d'une cible : `etat.choix`, `liste.value[0]`, `f().b`, et
 * `liste.value[?]` pour un indice calculé ; `null` pour ce qu'il ne sait lire.
 *
 * @param {object} noeud
 * @returns {string | null}
 */
const cheminDe = (noeud) => {
    if (noeud.type === 'Identifier') {
        return noeud.name
    }

    if (noeud.type === 'ThisExpression') {
        return 'this'
    }

    if (noeud.type === 'TSNonNullExpression' || noeud.type === 'ParenthesizedExpression') {
        return cheminDe(noeud.expression)
    }

    if (noeud.type === 'CallExpression' || noeud.type === 'OptionalCallExpression') {
        const appele = cheminDe(noeud.callee)

        return appele === null ? null : `${appele}()`
    }

    if (noeud.type !== 'MemberExpression' && noeud.type !== 'OptionalMemberExpression') {
        return null
    }

    const objet = cheminDe(noeud.object)

    if (objet === null) {
        return null
    }

    if (!noeud.computed) {
        return `${objet}.${noeud.property.name}`
    }

    return ['StringLiteral', 'NumericLiteral'].includes(noeud.property.type)
        ? `${objet}[${noeud.property.value}]`
        : `${objet}${INDICE_CALCULE}`
}

/**
 * Le chemin changé est rétabli : une affectation du `onFinish` vise ce chemin
 * même, ou un chemin au-dessus (`liste.value` rétablit `liste.value[?].x`).
 *
 * @param {string | null} chemin
 * @param {Set<string | null>} retablis
 */
const estRetabli = (chemin, retablis) =>
    chemin !== null &&
    [...retablis].some(
        (retabli) =>
            retabli !== null &&
            !retabli.includes(INDICE_CALCULE) &&
            (chemin === retabli || chemin.startsWith(`${retabli}.`) || chemin.startsWith(`${retabli}[`)),
    )

const estUneFonction = (noeud) =>
    ['ArrowFunctionExpression', 'FunctionExpression', 'FunctionDeclaration', 'ObjectMethod'].includes(noeud.type)

/**
 * Les noms que la source lie à un formulaire de `useForm`, et ceux qu'elle
 * déclare comme des fonctions.
 *
 * @param {object} programme
 * @returns {{ formulaires: Set<string>, fonctions: Set<string> }}
 */
const declarationsDe = (programme) => {
    const formulaires = new Set()
    const fonctions = new Set()

    walk(programme, {
        enter(noeud) {
            if (noeud.type === 'FunctionDeclaration' && noeud.id) {
                fonctions.add(noeud.id.name)
            }

            if (noeud.type !== 'VariableDeclarator' || noeud.id.type !== 'Identifier' || !noeud.init) {
                return
            }

            if (estUneFonction(noeud.init)) {
                fonctions.add(noeud.id.name)
            }

            if (
                noeud.init.type === 'CallExpression' &&
                noeud.init.callee.type === 'Identifier' &&
                noeud.init.callee.name === 'useForm'
            ) {
                formulaires.add(noeud.id.name)
            }
        },
    })

    return { formulaires, fonctions }
}

/**
 * Si l'appel est une visite d'Inertia : la place de ses options, le
 * formulaire qui la lance (`null` pour `router`), et la fonction que lui
 * passe `optimistic(…)` en chaîne (`router.optimistic(f).delete(…)`) ; sinon
 * `null`.
 *
 * @param {object} appel
 * @param {Set<string>} formulaires
 * @returns {{ place: number, formulaire: string | null, optimisteEnChaine: object | null } | null}
 */
const visiteDe = (appel, formulaires) => {
    const appele = appel.callee

    if (appele.type !== 'MemberExpression' || !VISITES.has(nomDeLaPropriete(appele))) {
        return null
    }

    const methode = nomDeLaPropriete(appele)
    let objet = appele.object
    let optimisteEnChaine = null

    if (
        objet.type === 'CallExpression' &&
        objet.callee.type === 'MemberExpression' &&
        nomDeLaPropriete(objet.callee) === 'optimistic'
    ) {
        optimisteEnChaine = objet.arguments[0] ?? null
        objet = objet.callee.object
    }

    if (objet.type === 'Identifier' && objet.name === 'router') {
        const place = PLACE_DES_OPTIONS_DU_ROUTEUR[methode]

        return place === undefined ? null : { place, formulaire: null, optimisteEnChaine }
    }

    // `form`, `useForm({})`, ou `form.transform(…)` qui rend le même formulaire.
    const racine = racineDe(objet)
    const formulaireEnLigne =
        objet.type === 'CallExpression' && objet.callee.type === 'Identifier' && objet.callee.name === 'useForm'
    const place = PLACE_DES_OPTIONS_DU_FORMULAIRE[methode]

    if ((!formulaires.has(racine) && !formulaireEnLigne) || place === undefined) {
        return null
    }

    return { place, formulaire: formulaireEnLigne ? null : racine, optimisteEnChaine }
}

/**
 * Une fonction : écrite là, ou un nom que le fichier déclare comme tel.
 * `optimistic: false` ou `undefined` ne reviennent pas.
 *
 * @param {object | null | undefined} noeud
 * @param {Set<string>} fonctions
 */
const estUneFonctionDuFichier = (noeud, fonctions) =>
    Boolean(noeud) && (estUneFonction(noeud) || (noeud.type === 'Identifier' && fonctions.has(noeud.name)))

/**
 * Les options passent par l'utilitaire, ou la visite porte l'option
 * `optimistic` d'Inertia : une fonction, dans les options ou en chaîne.
 *
 * @param {object | undefined} options
 * @param {object | null} optimisteEnChaine
 * @param {Set<string>} fonctions
 */
const optionsQuiReviennent = (options, optimisteEnChaine, fonctions) => {
    if (estUneFonctionDuFichier(optimisteEnChaine, fonctions)) {
        return true
    }

    if (!options) {
        return false
    }

    if (
        options.type === 'CallExpression' &&
        options.callee.type === 'Identifier' &&
        options.callee.name === 'revenirSiLaVisiteEchoue'
    ) {
        return true
    }

    return (
        options.type === 'ObjectExpression' &&
        options.properties.some(
            (propriete) =>
                propriete.key?.name === 'optimistic' &&
                (propriete.type === 'ObjectMethod' || estUneFonctionDuFichier(propriete.value, fonctions)),
        )
    )
}

/** Les chemins que les affectations du `onFinish` des options rétablissent. */
const cheminsRetablisALaFin = (options) => {
    const retablis = new Set()

    if (options?.type !== 'ObjectExpression') {
        return retablis
    }

    const fin = options.properties.find((propriete) => propriete.key?.name === 'onFinish')
    const corps = fin?.type === 'ObjectMethod' ? fin : fin?.value

    if (!corps || !estUneFonction(corps)) {
        return retablis
    }

    walk(corps, {
        enter(noeud) {
            if (noeud.type === 'AssignmentExpression' && noeud.left.type === 'MemberExpression') {
                retablis.add(cheminDe(noeud.left))
            }
        },
    })

    return retablis
}

/**
 * Ce que `fonction` change avant la position `avant`, hors de ses fonctions
 * imbriquées et des champs du formulaire qui lance la visite.
 *
 * @param {object} fonction
 * @param {number} avant
 * @param {Set<string>} formulaires les formulaires du fichier, dont les visites ne sont pas des changements
 * @param {string | null} formulaireDeLaVisite
 * @returns {Array<{ racine: string, chemin: string | null }>} les états changés, et le chemin de chaque changement
 */
const changementsAvant = (fonction, avant, formulaires, formulaireDeLaVisite) => {
    const changements = []
    const corps = fonction.type === 'Program' ? fonction : fonction.body

    walk(corps, {
        enter(noeud) {
            if (noeud.start >= avant) {
                this.skip()

                return
            }

            if (estUneFonction(noeud)) {
                this.skip()

                return
            }

            let cible = null

            if (noeud.type === 'AssignmentExpression' && noeud.left.type === 'MemberExpression') {
                cible = noeud.left
            } else if (noeud.type === 'UpdateExpression' && noeud.argument.type === 'MemberExpression') {
                cible = noeud.argument
            } else if (
                noeud.type === 'CallExpression' &&
                noeud.callee.type === 'MemberExpression' &&
                MUTATEURS.has(nomDeLaPropriete(noeud.callee)) &&
                visiteDe(noeud, formulaires) === null
            ) {
                cible = noeud.callee.object
            }

            if (cible === null || noeud.end > avant) {
                return
            }

            const racine = racineDe(cible)

            if (racine !== null && !HORS_DE_L_ETAT.has(racine) && racine !== formulaireDeLaVisite) {
                changements.push({ racine, chemin: cheminDe(cible) })
            }
        },
    })

    return changements
}

/**
 * Les visites d'un script précédées d'un changement d'état qu'elles ne
 * défont pas sur tout échec.
 *
 * @param {string} code le script
 * @param {number} decalage les lignes avant le script dans son fichier
 * @returns {Array<{ ligne: number, etats: string[] }>}
 */
export const visitesSansRetour = (code, decalage = 0) => {
    const programme = babelParse(code, { sourceType: 'module' }).program
    const { formulaires, fonctions } = declarationsDe(programme)
    const fautes = []
    const pile = [programme]

    walk(programme, {
        enter(noeud) {
            if (estUneFonction(noeud)) {
                pile.push(noeud)
            }

            if (noeud.type !== 'CallExpression') {
                return
            }

            const visite = visiteDe(noeud, formulaires)

            if (visite === null) {
                return
            }

            const options = noeud.arguments[visite.place]

            if (optionsQuiReviennent(options, visite.optimisteEnChaine, fonctions)) {
                return
            }

            const retablis = cheminsRetablisALaFin(options)
            const etats = [
                ...new Set(
                    changementsAvant(pile.at(-1), noeud.start, formulaires, visite.formulaire)
                        .filter(({ chemin }) => !estRetabli(chemin, retablis))
                        .map(({ racine }) => racine),
                ),
            ]

            if (etats.length > 0) {
                fautes.push({ ligne: noeud.loc.start.line + decalage, etats })
            }
        },
        leave(noeud) {
            if (estUneFonction(noeud)) {
                pile.pop()
            }
        },
    })

    return fautes
}

/** Les scripts d'un fichier, avec leur décalage dans le fichier. */
const scriptsDe = (chemin) => {
    const source = readFileSync(chemin, 'utf8')

    if (!chemin.endsWith('.vue')) {
        return [{ code: source, decalage: 0 }]
    }

    const { descriptor } = lireLeComposant(source, { filename: chemin })

    return [descriptor.script, descriptor.scriptSetup]
        .filter((bloc) => bloc !== null)
        .map((bloc) => ({ code: bloc.content, decalage: bloc.loc.start.line - 1 }))
}

const relatif = (chemin) => chemin.replace(jsRoot, 'resources/js')

describe('une mise à jour optimiste revient sur tout échec', () => {
    it('chaque visite précédée d’un changement d’état passe par revenirSiLaVisiteEchoue', () => {
        const fautes = []
        const exemptionsServies = new Set()

        for (const chemin of collectSourceFiles({ extensions: ['.vue', '.js'] })) {
            for (const { code, decalage } of scriptsDe(chemin)) {
                for (const { ligne, etats } of visitesSansRetour(code, decalage)) {
                    for (const etat of etats) {
                        const exemption = EXEMPTIONS.find(
                            (candidate) => candidate.fichier === relatif(chemin) && candidate.etat === etat,
                        )

                        if (exemption) {
                            exemptionsServies.add(exemption)
                        } else {
                            fautes.push(`${relatif(chemin)}:${ligne} change « ${etat} » avant la visite`)
                        }
                    }
                }
            }
        }

        expect(fautes).toEqual([])
        expect(
            EXEMPTIONS.filter((exemption) => !exemptionsServies.has(exemption)).map(
                (exemption) => `${exemption.fichier} : « ${exemption.etat} » n’est plus changé avant une visite`,
            ),
        ).toEqual([])
    })

    it('chaque exemption dit pourquoi', () => {
        for (const exemption of EXEMPTIONS) {
            expect(exemption.raison.length).toBeGreaterThan(30)
        }
    })
})

/*
 * La garde refuse ce qu'elle doit refuser : la suppression des séances et
 * l'interrupteur du repos automatique tels qu'ils étaient avant #2077, et
 * leurs variantes.
 */
describe('ce que la garde refuse et ce qu’elle laisse passer', () => {
    it.each([
        [
            'la suppression des séances, revenue dans onError seul',
            `const deleteForm = useForm({})
            const supprimer = (seance) => {
                const index = liste.value.findIndex((s) => s.id === seance.id)
                liste.value.splice(index, 1)
                deleteForm.delete(route('workouts.destroy', seance.id), {
                    onError: () => liste.value.splice(index, 0, seance),
                })
            }`,
            ['liste'],
        ],
        [
            'le repos automatique, revenu sans réseau seulement',
            `const basculer = (valeur) => {
                const precedent = actif.value
                actif.value = valeur
                router.patch('/reglage', { valeur }, {
                    onNetworkError: direSansReseau(MESSAGE, () => { actif.value = precedent }),
                })
            }`,
            ['actif'],
        ],
        [
            'la période des statistiques, jamais revenue',
            `const choisir = (periode) => {
                periodeCourante.value = periode
                router.visit(route('stats.index'), { data: { periode } })
            }`,
            ['periodeCourante'],
        ],
        [
            'un ensemble garni avant un rechargement',
            `const lire = (id) => {
                lus.add(id)
                router.reload({ only: ['notifications'] })
            }`,
            ['lus'],
        ],
        [
            'un champ incrémenté avant une visite d’un formulaire transformé',
            `const form = useForm({})
            const envoyer = () => {
                compteur.value++
                form.transform((donnees) => donnees).post('/envoi', {})
            }`,
            ['compteur'],
        ],
        [
            'un état rétabli dans onFinish, mais pas celui qui a changé',
            `const envoyer = () => {
                occupe.value = true
                choix.value = 'b'
                router.post('/envoi', {}, { onFinish: () => { occupe.value = false } })
            }`,
            ['choix'],
        ],
        [
            'un champ rétabli dans onFinish, quand un autre champ du même objet a changé',
            `const envoyer = () => {
                etat.occupe = true
                etat.choix = 'b'
                router.post('/envoi', {}, { onFinish: () => { etat.occupe = false } })
            }`,
            ['etat'],
        ],
        [
            'un indice calculé rétabli par un autre indice calculé',
            `const cocher = (i, j) => {
                lignes.value[i] = true
                router.post('/cocher', {}, { onFinish: () => { lignes.value[j] = false } })
            }`,
            ['lignes'],
        ],
        [
            'un router.get dont l’enveloppe est à la place des données, qu’Inertia enverrait sans l’appeler',
            `const choisir = (periode) => {
                periodeCourante.value = periode
                router.get('/stats', revenirSiLaVisiteEchoue(() => {}, { preserveState: true }))
            }`,
            ['periodeCourante'],
        ],
        [
            'une option optimistic qui n’est pas une fonction',
            `const supprimer = () => {
                liste.value.splice(0, 1)
                router.delete('/seances/1', { optimistic: false })
            }`,
            ['liste'],
        ],
        [
            'une option optimistic en chaîne qui n’est pas une fonction',
            `const supprimer = () => {
                liste.value.splice(0, 1)
                router.optimistic(undefined).delete('/seances/1')
            }`,
            ['liste'],
        ],
        [
            'les champs d’un autre formulaire que celui de la visite',
            `const filtres = useForm({ recherche: '' })
            const form = useForm({})
            const envoyer = () => {
                filtres.recherche = ''
                form.post('/envoi')
            }`,
            ['filtres'],
        ],
        [
            'les champs d’un formulaire avant une visite du routeur, qui ne les envoie pas',
            `const form = useForm({ recherche: '' })
            const envoyer = () => {
                form.recherche = ''
                router.post('/envoi', {})
            }`,
            ['form'],
        ],
    ])('refuse %s', (_cas, code, etats) => {
        expect(visitesSansRetour(code).map((faute) => faute.etats)).toEqual([etats])
    })

    it.each([
        [
            'une visite passée par l’utilitaire',
            `const supprimer = (seance) => {
                const remettre = retirerDeLaListe(() => liste.value, seance.id)
                router.delete('/seances/1', revenirSiLaVisiteEchoue(remettre, { preserveScroll: true }))
            }`,
        ],
        [
            'une visite qui porte l’option optimistic d’Inertia',
            `const supprimer = (seance) => {
                liste.value.splice(0, 1)
                router.delete('/seances/1', { optimistic: (props) => props })
            }`,
        ],
        [
            'une option optimistic écrite en méthode, ou nommée et déclarée comme fonction dans le fichier',
            `const sansLaSeance = (props) => props
            function sansLExercice(props) { return props }
            const supprimer = () => {
                liste.value.splice(0, 1)
                router.delete('/seances/1', { optimistic(props) { return props } })
                router.delete('/seances/2', { optimistic: sansLaSeance })
                router.delete('/exercices/3', { optimistic: sansLExercice })
            }`,
        ],
        [
            'une option optimistic en chaîne, sur le routeur ou sur un formulaire',
            `const form = useForm({})
            const supprimer = () => {
                liste.value.splice(0, 1)
                router.optimistic((props) => props).delete('/seances/1')
                form.optimistic((props) => props).delete('/seances/2')
            }`,
        ],
        [
            'un router.get dont l’enveloppe est à la place des options',
            `const choisir = (periode) => {
                periodeCourante.value = periode
                router.get('/stats', { periode }, revenirSiLaVisiteEchoue(() => {}, { preserveState: true }))
            }`,
        ],
        [
            'le chemin même que onFinish rétablit, ou ce qui est dessous',
            `const envoyer = () => {
                etat.occupe = true
                etat.choix.valeur = 'b'
                lignes.value[0] = true
                router.post('/envoi', {}, {
                    onFinish: () => {
                        etat.occupe = false
                        etat.choix = {}
                        lignes.value[0] = false
                    },
                })
            }`,
        ],
        [
            'un bouton occupé que onFinish libère',
            `const lancer = () => {
                enCours.value = true
                router.post('/lancer', {}, { onFinish: () => (enCours.value = false) })
            }`,
        ],
        [
            'les champs d’un formulaire, que la visite envoie',
            `const form = useForm({ quantite: 0 })
            const ajouter = (quantite) => {
                form.quantite = quantite
                form.post('/eau', { onError: () => triggerHaptic('error') })
            }`,
        ],
        [
            'un changement fait après la visite, ou dans un rappel défini avant elle',
            `const envoyer = () => {
                const revenir = () => { liste.value.splice(0, 0, 1) }
                router.post('/envoi', {}, { onSuccess: () => { liste.value = [] } })
                ouvert.value = false
            }`,
        ],
        [
            'une écriture de la file hors ligne, qui n’est pas une visite',
            `const retirer = (id) => {
                liste.value.splice(0, 1)
                SyncService.delete('/series/' + id).catch(() => {})
            }`,
        ],
    ])('laisse passer %s', (_cas, code) => {
        expect(visitesSansRetour(code)).toEqual([])
    })
})

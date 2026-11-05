import { describe, it, expect } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'
import { babelParse, walk } from 'vue/compiler-sfc'

/**
 * Un fuseau ne se pose que par `poserLeFuseau` (#2158).
 *
 * Quatorze fichiers simulaient un appareil hors d'UTC en écrivant
 * `process.env.TZ`, chacun à sa façon. Un seul vérifiait que le fuseau était
 * en vigueur : sous `--pool=threads`, où l'affectation reste sans effet, les
 * treize autres tournaient dans le fuseau de la machine, UTC sur la CI, et un
 * `parseCalendarDate` qui lit le jour comme un instant n'y faisait plus tomber
 * aucun cas. Trois rendaient le fuseau par une affectation, qui écrit la
 * chaîne « undefined » quand il n'était pas posé, et un ne le rendait jamais.
 *
 * `poserLeFuseau` (`tests/js/utils/fuseauDeLAppareil.js`) vérifie le décalage
 * à l'instant du cas et rend la fonction qui remet le fuseau d'avant. La garde
 * refuse, partout ailleurs dans tests/js et dans `vitest.setup.js`, les
 * écritures du fuseau qu'un test écrit, qui changent toutes le fuseau sous le
 * pool `forks` sans le vérifier :
 *
 * - une affectation à `process.env.TZ` (`['TZ']`, `??=` compris) ou à
 *   `process.env` lui-même, et une suppression par `delete` ;
 * - les mêmes par `globalThis.process` ou `global.process`, et par un nom qui
 *   tient `process` ou `process.env` (`const env = process.env`,
 *   `const { env } = process`), la portée n'étant pas suivie ;
 * - une clé calculée (`process.env[cle] = …`), qui peut valoir `TZ` ;
 * - `Object.assign` et `Object.defineProperties` vers `process.env`, sauf d'un
 *   objet écrit sur place sans clé `TZ` ni clé calculée ;
 * - `Object.defineProperty`, `Reflect.set`, `Reflect.defineProperty` et
 *   `Reflect.deleteProperty` sur `process.env`, pour `TZ` ou une clé calculée ;
 * - `vi.stubEnv('TZ', …)`, ou d'un premier argument qui se calcule.
 *
 * Elle lit le code et non le texte : la lecture
 * (`const origine = process.env.TZ`), la comparaison (`origine === undefined`),
 * un commentaire ou une chaîne qui citent ces formes passent, et un objet à
 * soi qui a une clé `TZ` aussi. Une écriture faite ailleurs que dans le source
 * qui la demande (une fonction qui reçoit `process.env` en paramètre, un
 * `eval`) lui échappe : elle tient les formes qu'un test écrit, pas un
 * contournement.
 */

const racineDesTests = resolve(__dirname, '..')
const racineDuDepot = resolve(racineDesTests, '../..')

/** Le seul fichier qui écrit le fuseau. */
const L_UTILITAIRE = join(racineDesTests, 'utils/fuseauDeLAppareil.js')

/** Ce que Vitest charge avant chaque fichier de test (`setupFiles` de vitest.config.js). */
const AVANT_CHAQUE_FICHIER = join(racineDuDepot, 'vitest.setup.js')

const fichiersJs = (dossier) =>
    readdirSync(dossier).flatMap((entree) => {
        const chemin = join(dossier, entree)

        if (statSync(chemin).isDirectory()) {
            return fichiersJs(chemin)
        }

        return chemin.endsWith('.js') ? [chemin] : []
    })

/**
 * Le texte d'une chaîne écrite telle quelle : `'TZ'`, `"TZ"` ou `` `TZ` ``.
 *
 * @param {object | undefined} noeud
 * @returns {string | null} `null` pour ce qui se calcule : un nom, un appel, un gabarit à trous.
 */
const texteLitteral = (noeud) => {
    if (noeud?.type === 'StringLiteral') {
        return noeud.value
    }

    return noeud?.type === 'TemplateLiteral' && noeud.expressions.length === 0 ? noeud.quasis[0].value.cooked : null
}

/**
 * Le nom d'une clé lue sans calcul : `.TZ`, `['TZ']`, `{ TZ: … }` ou `{ 'TZ': … }`.
 *
 * @param {object} cle La `property` d'un `MemberExpression`, ou la `key` d'une propriété d'objet.
 * @param {boolean} calculee Écrite entre crochets.
 * @returns {string | null} `null` pour une clé qui se calcule : `[cle]`.
 */
const nomDeLaCle = (cle, calculee) => (!calculee && cle.type === 'Identifier' ? cle.name : texteLitteral(cle))

/** Une clé qui peut nommer le fuseau : `TZ`, ou une clé qui se calcule et peut le valoir. */
const peutNommerLeFuseau = (nom) => nom === null || nom === 'TZ'

/** Les objets globaux par lesquels `process` se lit aussi : `globalThis.process`, `global.process`. */
const GLOBAUX = new Set(['globalThis', 'global'])

/**
 * `process`, `globalThis.process`, `global.process`, ou un nom qui le tient.
 *
 * @param {object} noeud
 * @param {{process: Set<string>, env: Set<string>}} alias
 * @returns {boolean}
 */
const estProcess = (noeud, alias) =>
    (noeud.type === 'Identifier' && alias.process.has(noeud.name)) ||
    (noeud.type === 'MemberExpression' &&
        noeud.object.type === 'Identifier' &&
        GLOBAUX.has(noeud.object.name) &&
        nomDeLaCle(noeud.property, noeud.computed) === 'process')

/**
 * `process.env`, lu sur l'un de ces chemins, ou un nom qui le tient.
 *
 * @param {object} noeud
 * @param {{process: Set<string>, env: Set<string>}} alias
 * @returns {boolean}
 */
const estLEnvironnement = (noeud, alias) =>
    (noeud.type === 'Identifier' && alias.env.has(noeud.name)) ||
    (noeud.type === 'MemberExpression' &&
        nomDeLaCle(noeud.property, noeud.computed) === 'env' &&
        estProcess(noeud.object, alias))

/**
 * Note le nom qu'une déclaration ou une affectation donne à `process` ou à
 * `process.env` : `env = process.env`, `{ env } = process`, `{ env: variables } = process`,
 * `p = process`, `{ process: p } = globalThis`.
 *
 * @param {object} cible Ce qui reçoit : un nom, ou un motif d'objet.
 * @param {object} valeur Ce qui est donné.
 * @param {{process: Set<string>, env: Set<string>}} alias Complété sur place.
 */
const lier = (cible, valeur, alias) => {
    if (cible.type === 'Identifier') {
        if (estProcess(valeur, alias)) {
            alias.process.add(cible.name)
        }

        if (estLEnvironnement(valeur, alias)) {
            alias.env.add(cible.name)
        }

        return
    }

    if (cible.type !== 'ObjectPattern') {
        return
    }

    for (const propriete of cible.properties) {
        const lie = propriete.value?.type === 'AssignmentPattern' ? propriete.value.left : propriete.value

        if (propriete.type !== 'ObjectProperty' || lie.type !== 'Identifier') {
            continue
        }

        const nom = nomDeLaCle(propriete.key, propriete.computed)

        if (nom === 'env' && estProcess(valeur, alias)) {
            alias.env.add(lie.name)
        }

        if (nom === 'process' && valeur.type === 'Identifier' && GLOBAUX.has(valeur.name)) {
            alias.process.add(lie.name)
        }
    }
}

/**
 * Les noms sous lesquels un source tient `process` et `process.env` : les
 * leurs, et ceux qu'il leur donne, alias d'alias compris. La portée n'est pas
 * suivie : un nom lié une fois l'est dans tout le fichier.
 *
 * @param {object} programme
 * @returns {{process: Set<string>, env: Set<string>}}
 */
const aliasDuSource = (programme) => {
    const alias = { process: new Set(['process']), env: new Set() }
    let connus

    do {
        connus = alias.process.size + alias.env.size

        walk(programme, {
            enter(noeud) {
                if (noeud.type === 'VariableDeclarator' && noeud.init) {
                    lier(noeud.id, noeud.init, alias)
                }

                if (noeud.type === 'AssignmentExpression' && noeud.operator === '=') {
                    lier(noeud.left, noeud.right, alias)
                }
            },
        })
    } while (alias.process.size + alias.env.size !== connus)

    return alias
}

/**
 * La cible d'une affectation ou d'un `delete` qui touche le fuseau :
 * `process.env.TZ`, `process.env[cle]`, ou `process.env` lui-même.
 *
 * @param {object} cible
 * @param {{process: Set<string>, env: Set<string>}} alias
 * @returns {boolean}
 */
const toucheLeFuseau = (cible, alias) =>
    cible.type === 'MemberExpression' &&
    (estLEnvironnement(cible, alias) ||
        (estLEnvironnement(cible.object, alias) && peutNommerLeFuseau(nomDeLaCle(cible.property, cible.computed))))

/** Ce qui écrit dans son premier argument la clé que nomme le second. */
const ECRIVENT_UNE_CLE = new Set([
    'Object.defineProperty',
    'Reflect.set',
    'Reflect.defineProperty',
    'Reflect.deleteProperty',
])

/** Ce qui y recopie les clés des arguments suivants. */
const RECOPIENT_DES_CLES = new Set(['Object.assign', 'Object.defineProperties'])

/**
 * Un objet écrit sur place dont aucune clé ne peut nommer le fuseau :
 * `{ NODE_ENV: 'test' }`, pas `{ TZ }`, `{ [cle]: … }`, `{ ...valeurs }` ni `valeurs`.
 *
 * @param {object} noeud
 * @returns {boolean}
 */
const epargneLeFuseau = (noeud) =>
    noeud.type === 'ObjectExpression' &&
    noeud.properties.every(
        (propriete) =>
            propriete.type !== 'SpreadElement' && !peutNommerLeFuseau(nomDeLaCle(propriete.key, propriete.computed)),
    )

/**
 * Un appel qui écrit le fuseau : `vi.stubEnv('TZ', …)` ou `vi.stubEnv(cle, …)`,
 * `Reflect.set(process.env, 'TZ', …)` et ses semblables, `Object.assign(process.env, { TZ })`.
 *
 * @param {object} appel Un `CallExpression`.
 * @param {{process: Set<string>, env: Set<string>}} alias
 * @returns {boolean}
 */
const appelEcritLeFuseau = ({ callee, arguments: [cible, ...suite] }, alias) => {
    const methode =
        callee.type === 'MemberExpression' && callee.object.type === 'Identifier'
            ? `${callee.object.name}.${nomDeLaCle(callee.property, callee.computed)}`
            : null

    if (methode === 'vi.stubEnv') {
        return peutNommerLeFuseau(texteLitteral(cible))
    }

    if (cible === undefined || !estLEnvironnement(cible, alias)) {
        return false
    }

    if (ECRIVENT_UNE_CLE.has(methode)) {
        return peutNommerLeFuseau(texteLitteral(suite[0]))
    }

    return RECOPIENT_DES_CLES.has(methode) && !suite.every(epargneLeFuseau)
}

/**
 * Les écritures du fuseau d'un source, avec leur ligne : une affectation, une
 * suppression, un appel qui l'écrit. Chacune nomme `env` ou `stubEnv` : un
 * source qui ne les nomme pas n'est pas lu plus avant.
 *
 * @param {string} source
 * @returns {{ligne: number, ecriture: string}[]}
 */
const ecrituresDuFuseau = (source) => {
    if (!/\benv\b|stubEnv/.test(source)) {
        return []
    }

    const programme = babelParse(source, { sourceType: 'module' }).program
    const alias = aliasDuSource(programme)
    const ecritures = []

    walk(programme, {
        enter(noeud) {
            const ecrit =
                (noeud.type === 'AssignmentExpression' && toucheLeFuseau(noeud.left, alias)) ||
                (noeud.type === 'UnaryExpression' &&
                    noeud.operator === 'delete' &&
                    toucheLeFuseau(noeud.argument, alias)) ||
                (noeud.type === 'CallExpression' && appelEcritLeFuseau(noeud, alias))

            if (ecrit) {
                ecritures.push({
                    ligne: noeud.loc.start.line,
                    ecriture: source.slice(noeud.start, noeud.end).split('\n')[0],
                })
            }
        },
    })

    return ecritures
}

describe('le fuseau des tests', () => {
    it('ne s’écrit que par poserLeFuseau, qui vérifie qu’il est en vigueur', () => {
        const fautifs = [...fichiersJs(racineDesTests), AVANT_CHAQUE_FICHIER]
            .filter((chemin) => chemin !== L_UTILITAIRE)
            .flatMap((chemin) =>
                ecrituresDuFuseau(readFileSync(chemin, 'utf8')).map(
                    ({ ligne, ecriture }) => `${relative(racineDuDepot, chemin)}:${ligne} ${ecriture}`,
                ),
            )

        expect(
            fautifs,
            'passer par poserLeFuseau (tests/js/utils/fuseauDeLAppareil.js) : sous --pool=threads, un fuseau écrit à la main reste sans effet, sans erreur',
        ).toEqual([])
    })

    it('s’écrit dans l’utilitaire, la seule exception de la garde', () => {
        expect(ecrituresDuFuseau(readFileSync(L_UTILITAIRE, 'utf8')).length).toBeGreaterThan(0)
    })
})

describe('la garde', () => {
    it('reconnaît chaque écriture du fuseau, à sa ligne', () => {
        const formes = [
            "process.env.TZ = 'America/New_York'",
            "process.env['TZ'] = 'Europe/Paris'",
            'process.env["TZ"] = fuseau',
            'process.env[`TZ`] = fuseau',
            "process.env.TZ ??= 'UTC'",
            'delete process.env.TZ',
            "delete process.env['TZ']",
            "vi.stubEnv('TZ', 'America/Toronto')",
            'vi.stubEnv(`TZ`, fuseau)',
            "vi.hoisted(() => {\n    process.env.TZ = 'America/New_York'\n})",
            "const env = process.env\nenv.TZ = 'America/New_York'",
            'const { env } = process\nenv[`TZ`] = fuseau',
            'const { env: variables } = process\ndelete variables.TZ',
            'let env\nenv = globalThis.process.env\nenv.TZ = fuseau',
            'const p = process\np.env.TZ = fuseau',
            "const { process: p } = globalThis\np.env['TZ'] = fuseau",
            "globalThis.process.env.TZ = 'America/New_York'",
            "global.process.env['TZ'] = fuseau",
            "Object.assign(process.env, { TZ: 'America/New_York' })",
            'Object.assign(process.env, valeurs)',
            "Object.assign(process.env, { NODE_ENV: 'test', ...valeurs })",
            'Object.defineProperties(process.env, { TZ: descripteur })',
            "Object.defineProperty(process.env, 'TZ', descripteur)",
            "Reflect.set(process.env, 'TZ', fuseau)",
            'Reflect.defineProperty(process.env, `TZ`, descripteur)',
            "Reflect.deleteProperty(process.env, 'TZ')",
            "const cle = 'TZ'\nprocess.env[cle] = fuseau",
            'delete process.env[cle]',
            'Reflect.set(process.env, cle, fuseau)',
            'vi.stubEnv(cle, fuseau)',
            'process.env = { ...process.env, TZ: fuseau }',
        ]

        expect(formes.filter((source) => ecrituresDuFuseau(source).length !== 1)).toEqual([])
        expect(ecrituresDuFuseau("const origine = process.env.TZ\n\nprocess.env.TZ = 'UTC'")).toEqual([
            { ligne: 3, ecriture: "process.env.TZ = 'UTC'" },
        ])
    })

    it('laisse passer la lecture, la comparaison, un commentaire, une chaîne et une autre variable', () => {
        const formes = [
            'const origine = process.env.TZ',
            'if (origine === undefined) {}',
            "expect(process.env.TZ === 'America/New_York').toBe(true)",
            'const pose = process.env.TZ !== undefined || process.env.TZ == null',
            '// `process.env.TZ = undefined` écrirait la chaîne « undefined »',
            '/* delete process.env.TZ */',
            "/**\n * `vi.stubEnv('TZ', …)` reste sans effet sous --pool=threads.\n */",
            'const exemple = "process.env.TZ = \'UTC\'"',
            "process.env.NODE_ENV = 'test'",
            "process.env.TZX = 'UTC'",
            "vi.stubEnv('LANG', 'en_US.UTF-8')",
            "const env = { TZ: 'UTC' }\nenv.TZ = process.env.TZ",
            'const p = { env: {} }\np.env.TZ = process.env.TZ',
            'const env = process.env\nconst fuseau = env.TZ',
            'const { TZ } = process.env',
            'const fuseau = process.env[cle]',
            "const copie = { ...process.env }\ncopie.TZ = 'UTC'",
            "Object.assign({}, process.env, { TZ: 'UTC' })",
            "Object.assign(process.env, { NODE_ENV: 'test' })",
            "Reflect.set(process.env, 'LANG', 'fr_FR.UTF-8')",
            "Reflect.get(process.env, 'TZ')",
        ]

        expect(formes.filter((source) => ecrituresDuFuseau(source).length > 0)).toEqual([])
    })
})

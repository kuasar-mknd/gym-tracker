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
 * refuse, partout ailleurs dans tests/js et dans `vitest.setup.js`, toute
 * écriture du fuseau : une affectation à `process.env.TZ`, ou à
 * `process.env['TZ']`, sa suppression par `delete`, et `vi.stubEnv('TZ', …)`,
 * sans effet de même sous `--pool=threads`. Elle lit le code et non le texte :
 * la lecture (`const origine = process.env.TZ`), la comparaison
 * (`origine === undefined`), un commentaire ou une chaîne qui citent ces
 * formes passent.
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
 * Le nom d'une propriété lue sans calcul : `.TZ`, `['TZ']`, `["TZ"]` ou `` [`TZ`] ``.
 *
 * @param {object} noeud Un `MemberExpression`.
 * @returns {string | null}
 */
const nomDeLaPropriete = ({ computed, property }) => {
    if (!computed) {
        return property.type === 'Identifier' ? property.name : null
    }

    if (property.type === 'StringLiteral') {
        return property.value
    }

    return property.type === 'TemplateLiteral' && property.expressions.length === 0
        ? property.quasis[0].value.cooked
        : null
}

/** `process.env.TZ`, sous l'une de ses écritures. */
const estLeFuseau = (noeud) =>
    noeud?.type === 'MemberExpression' &&
    nomDeLaPropriete(noeud) === 'TZ' &&
    noeud.object.type === 'MemberExpression' &&
    nomDeLaPropriete(noeud.object) === 'env' &&
    noeud.object.object.type === 'Identifier' &&
    noeud.object.object.name === 'process'

/** `vi.stubEnv('TZ', …)`. */
const estUnFuseauSimule = (noeud) =>
    noeud.type === 'CallExpression' &&
    noeud.callee.type === 'MemberExpression' &&
    noeud.callee.object.type === 'Identifier' &&
    noeud.callee.object.name === 'vi' &&
    nomDeLaPropriete(noeud.callee) === 'stubEnv' &&
    ((noeud.arguments[0]?.type === 'StringLiteral' && noeud.arguments[0].value === 'TZ') ||
        (noeud.arguments[0]?.type === 'TemplateLiteral' &&
            noeud.arguments[0].expressions.length === 0 &&
            noeud.arguments[0].quasis[0].value.cooked === 'TZ'))

/**
 * Les écritures du fuseau d'un source, avec leur ligne : une affectation, une
 * suppression, un `vi.stubEnv('TZ', …)`. Un source qui ne nomme pas `TZ`
 * n'est pas lu plus avant.
 *
 * @param {string} source
 * @returns {{ligne: number, ecriture: string}[]}
 */
const ecrituresDuFuseau = (source) => {
    if (!source.includes('TZ')) {
        return []
    }

    const ecritures = []

    walk(babelParse(source, { sourceType: 'module' }).program, {
        enter(noeud) {
            const ecrit =
                (noeud.type === 'AssignmentExpression' && estLeFuseau(noeud.left)) ||
                (noeud.type === 'UnaryExpression' && noeud.operator === 'delete' && estLeFuseau(noeud.argument)) ||
                estUnFuseauSimule(noeud)

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
            "const env = { TZ: 'UTC' }\nenv.TZ = 'Europe/Paris'",
        ]

        expect(formes.filter((source) => ecrituresDuFuseau(source).length > 0)).toEqual([])
    })
})

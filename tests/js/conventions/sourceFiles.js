import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, resolve } from 'node:path'

/**
 * The walk every convention guard needs.
 *
 * Each guard had written its own copy — three of them by the time the calendar
 * guards arrived. The walk is not the interesting part of a guard; the pattern
 * it looks for is. One module here keeps the guards down to the pattern.
 */
export const jsRoot = resolve(__dirname, '../../../resources/js')

/**
 * @param {{extensions?: string[], skip?: string[]}} options
 *   extensions — which files to visit. Defaults to Vue single-file modules.
 *   skip — paths, relative to resources/js, to leave out. A module that
 *   documents a banned form in its own comments has to be exempt from the
 *   guard that bans it.
 * @returns {string[]} Absolute paths.
 */
export const collectSourceFiles = ({ extensions = ['.vue'], skip = [] } = {}) => {
    const skipped = skip.map((relative) => join(jsRoot, relative))

    const walk = (directory) =>
        readdirSync(directory).flatMap((entry) => {
            const fullPath = join(directory, entry)

            if (statSync(fullPath).isDirectory()) {
                return walk(fullPath)
            }

            const wanted = extensions.some((extension) => entry.endsWith(extension))

            return wanted && !skipped.includes(fullPath) ? [fullPath] : []
        })

    return walk(jsRoot)
}

/**
 * Reports offenders as repo-relative paths, which is what a failing guard has
 * to print to be actionable.
 *
 * @param {RegExp} pattern
 * @param {{extensions?: string[], skip?: string[]}} options
 * @returns {string[]}
 */
export const filesMatching = (pattern, options = {}) =>
    collectSourceFiles(options)
        .filter((path) => pattern.test(readFileSync(path, 'utf8')))
        .map((path) => path.replace(jsRoot, 'resources/js'))

/**
 * Le texte qu'un fragment de gabarit met réellement sous les yeux.
 *
 * Écrite d'abord comme un `replace(/<[^>]*>/g, '')`, cette fonction a fait
 * tomber CodeQL sur `js/incomplete-multi-character-sanitization` : la requête
 * reconnaît la forme « je retire les balises avec une expression régulière » et
 * rappelle, à raison en général, qu'une telle passe peut RECRÉER ce qu'elle
 * prétend supprimer (`<scr<script>ipt>`).
 *
 * L'entrée est ici le source du dépôt, pas une donnée hostile, et rien n'est
 * réinjecté nulle part. Mais une exception vaut moins qu'un code qui n'a pas
 * besoin d'être excusé : on découpe le fragment en alternant balises et texte,
 * puis on ne garde que le texte. Rien n'est retiré, donc rien ne peut
 * réapparaître.
 *
 * Les régions `aria-hidden="true"` sont écartées avec leur contenu : ce sont les
 * ligatures Material Symbols, qui ajoutaient « add » devant chaque « Ajouter ».
 *
 * @param {string} fragment
 * @returns {string}
 */
export const visibleText = (fragment) => {
    let masquees = 0
    let texte = ''

    for (const morceau of fragment.split(/(<[^<>]*>)/)) {
        if (!morceau.startsWith('<')) {
            if (masquees === 0) {
                texte += morceau
            }

            continue
        }

        if (morceau.startsWith('</')) {
            masquees = Math.max(0, masquees - 1)

            continue
        }

        const autoFermante = morceau.endsWith('/>')

        if (!autoFermante && (masquees > 0 || morceau.includes('aria-hidden="true"'))) {
            masquees += 1
        }
    }

    return texte.replace(/\s+/g, ' ').trim()
}

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

import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'

import { collectSourceFiles, jsRoot } from './sourceFiles'

/**
 * A full-page background overlay that fades in on hover. These carry no action,
 * so staying invisible on touch is the intended behaviour.
 */
const isDecorativeOverlay = (classList) =>
    classList.includes('pointer-events-none') || (classList.includes('inset-0') && /\bbg-/.test(classList))

describe('touch affordances', () => {
    /**
     * `opacity-0 group-hover:opacity-100` reveals a control on hover — an event
     * that never fires on a touchscreen, so the control is simply gone on the
     * phones this app targets. The project's own working pattern keeps it
     * visible and defers the hover reveal to `sm:` and up, as JournalList.vue
     * and ExerciseCard.vue do. Habits, Measurements and PlateCalculator each
     * shipped the unprefixed form and lost their delete buttons on mobile.
     */
    it('never hides an interactive control behind hover alone', () => {
        const classAttribute = /class="([^"]*)"/g

        const offenders = collectSourceFiles().flatMap((file) => {
            const contents = readFileSync(file, 'utf8')

            return [...contents.matchAll(classAttribute)]
                .map((match) => match[1])
                .filter((classList) => {
                    const hidesByDefault = /(?:^|\s)opacity-0(?:\s|$)/.test(classList)
                    const revealsOnHover = /(?:^|\s)group-hover:opacity-100(?:\s|$)/.test(classList)

                    return hidesByDefault && revealsOnHover && !isDecorativeOverlay(classList)
                })
                .map((classList) => `${file.replace(jsRoot, 'resources/js')}: "${classList}"`)
        })

        expect(offenders).toEqual([])
    })
})

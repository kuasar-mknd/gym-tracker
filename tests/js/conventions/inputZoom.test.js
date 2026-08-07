import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'

import { collectSourceFiles, jsRoot } from './sourceFiles'

/**
 * Matches an <input>, <select> or <textarea> tag with everything up to its
 * closing bracket, so the class attribute inspected belongs to that control and
 * not to a neighbouring label.
 */
const formControlTag = /<(input|select|textarea)\b[^>]*>/gis

const UNDERSIZED = /(?:^|\s)text-(xs|sm)(?:\s|"|$)/

describe('form control sizing', () => {
    /**
     * iOS Safari zooms the viewport when a form control smaller than 16px takes
     * focus, then leaves the page zoomed. The app used to mask this with
     * `maximum-scale=1` on the viewport, which suppressed the zoom by also
     * forbidding the user to zoom at all — a WCAG 1.4.4 failure. Removing that
     * lock was the right call and made every sub-16px control a real defect.
     *
     * GlassInput already pins text-base at every size for this reason. This
     * catches the raw controls that bypass it, as the warm-up calculator and both
     * template editors did.
     */
    it('never ships a form control below the iOS zoom threshold', () => {
        const offenders = collectSourceFiles().flatMap((file) => {
            const contents = readFileSync(file, 'utf8')

            return [...contents.matchAll(formControlTag)]
                .filter((match) => {
                    const classAttribute = /class="([^"]*)"/i.exec(match[0])

                    return classAttribute !== null && UNDERSIZED.test(classAttribute[1])
                })
                .map((match) => `${file.replace(jsRoot, 'resources/js')}: ${match[0].slice(0, 80)}…`)
        })

        expect(offenders).toEqual([])
    })
})

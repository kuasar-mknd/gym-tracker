import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'

import { collectSourceFiles, filesMatching, jsRoot } from './sourceFiles'

/**
 * Client-side placeholder ids must never reach the server.
 *
 * Optimistic writes show a row under an id the server has never issued
 * (`temp-4`). Every mutation that followed used to take that id straight from
 * the local object and put it in a URL or a payload. The server received
 * `PATCH /api/v1/sets/temp-3` and `workout_line_id: "temp-1"` — both observed
 * in production traffic, both refused.
 *
 * Live that costs one edit. Queued it costs the session: a payload sitting in
 * the offline queue keeps the placeholder baked in, so when the queue drained,
 * workout 8 got its five lines created inside two seconds with real ids while
 * every queued set still pointed at `temp-1`. Five exercises, zero sets.
 *
 * Utils/pendingIds.js resolves a placeholder to the id the server issued, or to
 * null when there is nothing to talk about. These guard the call sites, because
 * the helper existing is not the same as every caller using it.
 *
 * pendingIds.js itself is exempt: it quotes the banned forms in the comments
 * that explain why it exists.
 */
const exemptions = { extensions: ['.vue', '.js'], skip: ['Utils/pendingIds.js'] }

/** Identifiers a resolve() call is allowed to have produced. */
const RESOLVED = /^real[A-Z_]/

describe('server ids', () => {
    /**
     * A set or line route parameter has to come from a resolved id. `set.id`,
     * `setId`, `lineId` and friends are whatever the local object holds, which
     * for a row created moments ago is a placeholder.
     */
    it('never puts an unresolved id in a sets or workout-lines route', () => {
        const routeParameter =
            /route\(\s*['"]api\.v1\.(?:sets|workout-lines)\.(?:update|destroy|show)['"]\s*,\s*\{\s*[\w-]+\s*:\s*([A-Za-z_$][\w.$]*)/g

        const offenders = collectSourceFiles(exemptions).flatMap((path) => {
            const source = readFileSync(path, 'utf8')

            return [...source.matchAll(routeParameter)]
                .filter(([, identifier]) => !RESOLVED.test(identifier))
                .map(([match]) => `${path.replace(jsRoot, 'resources/js')}: ${match}`)
        })

        expect(offenders).toEqual([])
    })

    /**
     * Same rule for the body. A set is created with the line it belongs to, and
     * that line may itself still be waiting on the server — adding a set right
     * after the exercise is the most ordinary thing to do on this screen.
     */
    it('never puts an unresolved id in a workout_line_id payload', () => {
        const payloadKey = /workout_line_id\s*:\s*([A-Za-z_$][\w.$]*)/g

        const offenders = collectSourceFiles(exemptions).flatMap((path) => {
            const source = readFileSync(path, 'utf8')

            return [...source.matchAll(payloadKey)]
                .filter(([, identifier]) => !RESOLVED.test(identifier))
                .map(([match]) => `${path.replace(jsRoot, 'resources/js')}: ${match}`)
        })

        expect(offenders).toEqual([])
    })

    /**
     * The rollback that is not one. SyncService rejects with `isOffline` when it
     * has queued a write rather than performed it, and callers read that as
     * "keep the value on screen". A `.catch` that rolls back unconditionally
     * throws away an edit the user made and the queue still intends to send.
     */
    it('never rolls an optimistic write back without checking isOffline', () => {
        const sources = collectSourceFiles(exemptions)
            .filter((path) => readFileSync(path, 'utf8').includes('SyncService'))
            .map((path) => path.replace(jsRoot, 'resources/js'))

        expect(sources.length).toBeGreaterThan(0)

        const uncheckedRollback = /\.catch\(\s*\(\s*\w*\s*\)\s*=>\s*\{\s*(?![^}]*isOffline)[^}]*\.splice\(/

        expect(filesMatching(uncheckedRollback, exemptions)).toEqual([])
    })
})

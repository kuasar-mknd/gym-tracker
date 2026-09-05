import { describe, it, expect } from 'vitest'
import { useIdentiteDesRangees } from '@/composables/useIdentiteDesRangees'
import { PendingIds } from '@/Utils/pendingIds'

describe('l’identité des rangées', () => {
    it('frappe des identifiants provisoires et des clefs uniques, et rend la clef ou l’identifiant', () => {
        const identite = useIdentiteDesRangees()

        expect([identite.nouvelIdTemporaire(), identite.nouvelIdTemporaire()]).toEqual(['temp-1', 'temp-2'])
        expect([identite.newRowKey(), identite.newRowKey()]).toEqual(['row-1', 'row-2'])
        expect(identite.rowKey({ id: 4, _rowKey: 'row-9' })).toBe('row-9')
        expect(identite.rowKey({ id: 4 })).toBe(4)
        expect(identite.pendingIds).toBeInstanceOf(PendingIds)
        expect(identite.queuedLineIds).toBeInstanceOf(Set)
    })
})

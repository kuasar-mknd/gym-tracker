import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { nextTick } from 'vue'

import { useFiltreDansLUrl } from '@/composables/useFiltreDansLUrl'

beforeEach(() => {
    window.history.replaceState({ inertia: 'état' }, '', '/exercises')
})

afterEach(() => {
    vi.restoreAllMocks()
})

describe('un filtre qui vit dans l’URL', () => {
    it('vaut le défaut quand l’URL ne dit rien, et ce que l’URL dit sinon', () => {
        expect(useFiltreDansLUrl('category').value).toBe('all')

        window.history.replaceState({}, '', '/exercises?category=Dos')

        expect(useFiltreDansLUrl('category').value).toBe('Dos')
        expect(useFiltreDansLUrl('type', 'tous').value).toBe('tous')
    })

    it('écrit sa valeur dans l’URL sans visite Inertia, en gardant l’état de l’historique', async () => {
        const replace = vi.spyOn(window.history, 'replaceState')
        const categorie = useFiltreDansLUrl('category')

        categorie.value = 'Jambes'
        await nextTick()

        expect(window.location.search).toBe('?category=Jambes')
        expect(replace.mock.calls[0][0]).toEqual({ inertia: 'état' })
    })

    it('retire le paramètre quand on revient au défaut, sans toucher aux autres', async () => {
        window.history.replaceState({}, '', '/exercises?page=2&category=Dos')
        const categorie = useFiltreDansLUrl('category')

        categorie.value = 'all'
        await nextTick()

        expect(window.location.search).toBe('?page=2')
    })
})

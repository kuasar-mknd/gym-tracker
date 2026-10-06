import { describe, it, expect, vi, afterEach } from 'vitest'

import { creerLesAttentesDeRejeu } from '@/Utils/attentesDeRejeu'

const annoncer = (type, detail) => window.dispatchEvent(new CustomEvent(type, { detail }))

afterEach(() => {
    vi.restoreAllMocks()
})

/**
 * Ce que devient une écriture mise en file, attendu par l'écran qui l'a faite :
 * le vidage l'annonce rejouée, refusée ou retirée (#1960).
 */
describe('les attentes de rejeu', () => {
    it('rend ce que l’écriture a produit, ce qu’elle a emporté et ce qui la rattrape, et ignore les autres', async () => {
        const attentes = creerLesAttentesDeRejeu()
        const rejeu = attentes.attendre('q1')
        const rattrapee = attentes.attendre('q3')

        annoncer('sync:replayed', { queueId: 'q2', data: { id: 99 } })
        annoncer('sync:replayed', { queueId: 'q1', data: { id: 100 }, envoye: { reps: 3 } })
        annoncer('sync:replayed', { queueId: 'q3', data: { id: 101 }, envoye: { reps: 3 }, ajustement: 'q4' })

        await expect(rejeu).resolves.toEqual({ data: { id: 100 }, envoye: { reps: 3 }, ajustement: null })
        await expect(rattrapee).resolves.toEqual({ data: { id: 101 }, envoye: { reps: 3 }, ajustement: 'q4' })
    })

    it('dit une écriture refusée ou retirée', async () => {
        const attentes = creerLesAttentesDeRejeu()
        const refusee = attentes.attendre('q1')
        const retiree = attentes.attendre('q2')

        annoncer('sync:failed', { queueId: 'q1', status: 422 })
        annoncer('sync:retired', { queueIds: ['q2'] })

        await expect(refusee).resolves.toEqual({ refusee: true })
        await expect(retiree).resolves.toEqual({ retiree: true })
    })

    it('n’attend rien d’une écriture qui n’est pas en file', async () => {
        await expect(creerLesAttentesDeRejeu().attendre(undefined)).resolves.toBeNull()
    })

    it('n’écoute que tant qu’une attente existe, et plus du tout une fois l’écran parti', async () => {
        const retire = vi.spyOn(window, 'removeEventListener')
        const attentes = creerLesAttentesDeRejeu()

        const premiere = attentes.attendre('q1')
        attentes.attendre('q2')
        annoncer('sync:replayed', { queueId: 'q1', data: null })
        await premiere

        expect(retire).not.toHaveBeenCalled()

        attentes.oublierTout()

        expect(retire.mock.calls.map(([type]) => type)).toEqual(['sync:replayed', 'sync:failed', 'sync:retired'])
    })

    it('se détache seule quand la dernière attente est réglée', async () => {
        const retire = vi.spyOn(window, 'removeEventListener')
        const attentes = creerLesAttentesDeRejeu()
        const rejeu = attentes.attendre('q1')

        annoncer('sync:replayed', { queueId: 'q1' })

        await expect(rejeu).resolves.toEqual({ data: null, envoye: null, ajustement: null })
        expect(retire).toHaveBeenCalledWith('sync:replayed', expect.any(Function))
    })
})

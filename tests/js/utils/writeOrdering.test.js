import { describe, it, expect, vi } from 'vitest'
import { createWriteSequencer, createWriteQueue } from '@/Utils/writeOrdering'

/**
 * Une promesse dont le test décide du moment de résolution.
 *
 * C'est tout l'outillage nécessaire pour transformer « la réponse arrive parfois
 * dans le désordre » en « la réponse arrive toujours dans le désordre ».
 */
const deferred = () => {
    let resolve
    let reject
    const promise = new Promise((res, rej) => {
        resolve = res
        reject = rej
    })

    return { promise, resolve, reject }
}

describe('createWriteSequencer', () => {
    it('ignore une réponse dépassée par une plus récente', () => {
        const { next, isLatest } = createWriteSequencer()

        const premier = next('set:1')
        const second = next('set:1')

        // L'ordre d'ARRIVÉE est inversé : le premier envoi répond en dernier.
        expect(isLatest('set:1', second)).toBe(true)
        expect(isLatest('set:1', premier)).toBe(false)
    })

    it('compte chaque clé séparément', () => {
        const { next, isLatest } = createWriteSequencer()

        const poids = next('set:1:weight')
        next('set:1:reps')

        // Écrire dans un champ ne doit pas périmer l'écriture d'un autre.
        expect(isLatest('set:1:weight', poids)).toBe(true)
    })

    it('repart de un pour une clé jamais vue', () => {
        const { next } = createWriteSequencer()

        expect(next('set:9')).toBe(1)
        expect(next('set:9')).toBe(2)
    })
})

describe('createWriteQueue', () => {
    it('ne lance pas la seconde écriture avant que la première ait rendu', async () => {
        const { queue } = createWriteQueue()
        const premier = deferred()
        const seconde = vi.fn(() => Promise.resolve('b'))

        queue('set:1', () => premier.promise)
        const attente = queue('set:1', seconde)

        // La première n'a pas rendu : la seconde ne doit pas être partie.
        await Promise.resolve()
        expect(seconde).not.toHaveBeenCalled()

        premier.resolve('a')
        await attente

        expect(seconde).toHaveBeenCalledOnce()
    })

    it('laisse partir la suivante même quand la précédente échoue', async () => {
        const { queue } = createWriteQueue()
        const suivante = vi.fn(() => Promise.resolve('ok'))

        // Une requête refusée ne doit pas geler la ressource pour la session.
        queue('set:1', () => Promise.reject(new Error('422'))).catch(() => {})

        await expect(queue('set:1', suivante)).resolves.toBe('ok')
        expect(suivante).toHaveBeenCalledOnce()
    })

    it('ne fait pas attendre une clé derrière une autre', async () => {
        const { queue } = createWriteQueue()
        const bloquee = deferred()
        const libre = vi.fn(() => Promise.resolve('libre'))

        queue('completion:1', () => bloquee.promise)

        // Valider une série et taper dedans sont indépendants : la seconde clé
        // ne doit rien attendre.
        await expect(queue('set:1:weight', libre)).resolves.toBe('libre')
        expect(libre).toHaveBeenCalledOnce()

        bloquee.resolve('fini')
    })

    it('rend le résultat de chaque écriture à son propre appelant', async () => {
        const { queue } = createWriteQueue()

        const premier = queue('set:1', () => Promise.resolve('un'))
        const second = queue('set:1', () => Promise.resolve('deux'))

        await expect(premier).resolves.toBe('un')
        await expect(second).resolves.toBe('deux')
    })
})

/**
 * Les deux protections ensemble, sur le scénario qui les a fait naître.
 *
 * Valider une série puis l'invalider aussitôt envoie deux PATCH sur la même
 * ligne. Sans la file, les deux partent en même temps ; sans le séquenceur, la
 * réponse la plus ancienne arrive parfois en dernier et recoche la case à
 * l'écran — tout en laissant le serveur sur l'autre valeur.
 */
describe('les deux ensemble', () => {
    it('applique la dernière intention, pas la dernière réponse', async () => {
        const { next, isLatest } = createWriteSequencer()
        const { queue } = createWriteQueue()

        const cle = 'completion:1'
        /** @type {string[]} */
        const applique = []

        const reponses = { valide: deferred(), invalide: deferred() }

        const ecrire = (intention, reponse) => {
            const seq = next(cle)

            return queue(cle, () =>
                reponse.promise.then((valeur) => {
                    if (!isLatest(cle, seq)) {
                        return
                    }

                    applique.push(valeur)
                }),
            )
        }

        const premiere = ecrire('validée', reponses.valide)
        const seconde = ecrire('invalidée', reponses.invalide)

        // Les réponses atterrissent dans le désordre : celle de la première
        // écriture arrive après celle de la seconde.
        reponses.invalide.resolve('invalidée')
        reponses.valide.resolve('validée')

        await Promise.all([premiere, seconde])

        // Une seule valeur appliquée, et c'est la dernière voulue.
        expect(applique).toEqual(['invalidée'])
    })
})

describe('forget', () => {
    it('repart de zéro pour une clé oubliée', async () => {
        const { queue, forget } = createWriteQueue()
        const bloquee = deferred()
        const apres = vi.fn(() => Promise.resolve('après'))

        queue('set:1', () => bloquee.promise)

        // La ligne disparaît : rien ne doit plus s'empiler derrière elle, sinon
        // les entrées survivraient à toutes les séries que la page a affichées.
        forget('set:1')

        await expect(queue('set:1', apres)).resolves.toBe('après')
        expect(apres).toHaveBeenCalledOnce()

        bloquee.resolve('avant')
    })
})

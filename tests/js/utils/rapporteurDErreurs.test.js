import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { installerLeRapporteurDErreurs, envoyerParFetch } from '@/Utils/rapporteurDErreurs'

const monter = (envoyer = vi.fn().mockResolvedValue({ ok: true })) => {
    const app = { config: {} }
    const rapporteur = installerLeRapporteurDErreurs(app, { envoyer })
    return { app, envoyer, ...rapporteur }
}

beforeEach(() => {
    globalThis.route = (nom) => `/${nom}`
    document.body.innerHTML = '<meta name="csrf-token" content="jeton" />'
})

afterEach(() => {
    vi.restoreAllMocks()
})

describe('rapporter une erreur', () => {
    it('envoie le type, le message, la position, la pile, la page et le navigateur', async () => {
        const { rapporter, envoyer } = monter()

        expect(rapporter('error', 'boum', 'main.js', 12, 3, 'TypeError: boum\n    at main.js:12:3')).toBe(true)
        await Promise.resolve()

        expect(envoyer).toHaveBeenCalledWith({
            type: 'error',
            message: 'boum',
            source: 'main.js',
            ligne: 12,
            colonne: 3,
            pile: 'TypeError: boum\n    at main.js:12:3',
            url: window.location.href,
            agent: navigator.userAgent,
        })
    })

    it('n’envoie qu’une fois la même erreur, et dix erreurs au plus par page', async () => {
        const { rapporter, envoyer } = monter()

        expect(rapporter('error', 'boum', 'main.js', 12)).toBe(true)
        expect(rapporter('error', 'boum', 'main.js', 12)).toBe(false)

        for (let i = 1; i < 10; i++) {
            expect(rapporter('error', `erreur ${i}`)).toBe(true)
        }
        expect(rapporter('error', 'la onzième')).toBe(false)
        await Promise.resolve()

        expect(envoyer).toHaveBeenCalledTimes(10)
    })

    it('ignore un message vide, tronque un message long et se tait quand l’envoi échoue', async () => {
        const envoyer = vi.fn().mockRejectedValue(new Error('hors ligne'))
        const { rapporter } = monter(envoyer)

        expect(rapporter('error', '')).toBe(false)
        expect(rapporter('error', null)).toBe(false)
        expect(rapporter('error', 'x'.repeat(3000), null, null, null, 'y'.repeat(30000))).toBe(true)
        await Promise.resolve()
        await Promise.resolve()

        expect(envoyer).toHaveBeenCalledTimes(1)
        expect(envoyer.mock.calls[0][0].message).toHaveLength(2000)
        expect(envoyer.mock.calls[0][0].pile).toHaveLength(20000)
    })
})

describe('ce que la page rapporte d’elle-même', () => {
    it('écoute les erreurs non rattrapées de la fenêtre', async () => {
        const { envoyer } = monter()

        window.dispatchEvent(
            new ErrorEvent('error', {
                message: 'boum',
                filename: 'main.js',
                lineno: 4,
                colno: 8,
                error: new Error('boum'),
            }),
        )
        await Promise.resolve()

        expect(envoyer).toHaveBeenCalledWith(
            expect.objectContaining({ type: 'error', message: 'boum', source: 'main.js', ligne: 4, colonne: 8 }),
        )
        expect(envoyer.mock.calls[0][0].pile).toContain('boum')
    })

    it('écoute les promesses rejetées, avec ou sans objet Error', async () => {
        const { envoyer } = monter()

        const avecErreur = new Event('unhandledrejection')
        avecErreur.reason = new Error('promesse cassée')
        window.dispatchEvent(avecErreur)

        const sansErreur = new Event('unhandledrejection')
        sansErreur.reason = 'texte brut'
        window.dispatchEvent(sansErreur)
        await Promise.resolve()

        expect(envoyer).toHaveBeenNthCalledWith(
            1,
            expect.objectContaining({ type: 'unhandledrejection', message: 'promesse cassée' }),
        )
        expect(envoyer).toHaveBeenNthCalledWith(
            2,
            expect.objectContaining({ type: 'unhandledrejection', message: 'texte brut', pile: null }),
        )
    })

    it('rapporte les erreurs de rendu Vue puis laisse la main au gestionnaire précédent, ou à la console', async () => {
        const precedent = vi.fn()
        const app = { config: { errorHandler: precedent } }
        const envoyer = vi.fn().mockResolvedValue({ ok: true })
        installerLeRapporteurDErreurs(app, { envoyer })
        const erreur = new Error('rendu cassé')

        app.config.errorHandler(erreur, { nom: 'Composant' }, 'render function')
        await Promise.resolve()

        expect(envoyer).toHaveBeenCalledWith(
            expect.objectContaining({ type: 'vue', message: 'rendu cassé', source: 'render function' }),
        )
        expect(precedent).toHaveBeenCalledWith(erreur, { nom: 'Composant' }, 'render function')

        const console_ = vi.spyOn(console, 'error').mockImplementation(() => {})
        const { app: sansPrecedent } = monter()
        sansPrecedent.config.errorHandler('texte', null, 'setup')
        expect(console_).toHaveBeenCalledWith('texte')
    })
})

describe('l’envoi', () => {
    it('poste en JSON sur la route nommée, avec le jeton CSRF et keepalive', async () => {
        const fetch = vi.spyOn(globalThis, 'fetch').mockResolvedValue({ ok: true })

        await envoyerParFetch({ type: 'error', message: 'boum' })

        expect(fetch).toHaveBeenCalledWith('/erreurs-navigateur.store', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': 'jeton' },
            body: JSON.stringify({ type: 'error', message: 'boum' }),
            keepalive: true,
        })
    })
})

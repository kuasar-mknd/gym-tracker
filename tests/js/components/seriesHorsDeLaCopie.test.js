import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

/*
 * Ce qu'une copie hors ligne de la séance n'a pas encore (#2048, partie E) :
 * servie depuis une copie plus ancienne que la note des écritures acceptées,
 * la séance dit que des séries enregistrées après l'heure de sa copie n'y
 * apparaissent pas. Le vrai client d'Inertia, la vraie page et le vrai magasin
 * le jouent dans `seanceServieDUneCopieAvecInertia.test.js` ; ici, chaque
 * cas, d'après la marque de la page et la note.
 */

/** La page d'Inertia : rendue par le serveur, ou servie depuis sa copie hors ligne. */
const etat = vi.hoisted(() => ({ page: null }))

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await vi.importActual('vue')
    etat.page = reactive({ props: {} })

    return { usePage: () => etat.page }
})

const { default: SeriesHorsDeLaCopie } = await import('@/Components/Workout/SeriesHorsDeLaCopie.vue')
const { noterUneEcritureAcceptee } = await import('@/Utils/ecrituresAcceptees')

/** Le lundi 12 octobre 2026 à 18:20, à l'heure de l'appareil : l'instantané de la copie. */
const A_18_20 = new Date(2026, 9, 12, 18, 20).getTime()
const UNE_MINUTE = 60_000

const servir = ({ copieDu = A_18_20 - 30 * UNE_MINUTE, datesDesProps = { workout: A_18_20 }, compte = 7 } = {}) => {
    etat.page.props = { auth: { user: { id: compte } }, horsLigne: { copieDu, raison: 'reseau', datesDesProps } }
}

const monter = (seance = 5) => mount(SeriesHorsDeLaCopie, { props: { seance }, global: { directives: { press: {} } } })
const message = (wrapper) => wrapper.find('[dusk="series-hors-de-la-copie"]')

beforeEach(() => {
    localStorage.clear()
    etat.page.props = { auth: { user: { id: 7 } } }
})

describe('SeriesHorsDeLaCopie', () => {
    it('dit qu’une série acceptée après la copie n’y est pas, mais qu’elle est bien enregistrée', () => {
        servir()
        noterUneEcritureAcceptee({ compte: 7, seance: 5, instant: A_18_20 + UNE_MINUTE })
        const note = message(monter())

        expect(note.exists()).toBe(true)
        expect(note.attributes('role')).toBe('status')
        expect(note.get('span:last-child').text().replace(/\s+/g, ' ')).toBe(
            "Des séries enregistrées après 18:20 n'apparaissent pas ici : elles sont bien enregistrées.",
        )
    })

    /*
     * La séance se verse dans sa copie à chaque instantané, bien après sa
     * réponse complète : c'est la date de sa prop qui compte.
     */
    it('se date de la prop de la séance, pas de la dernière réponse complète', () => {
        servir()
        noterUneEcritureAcceptee({ compte: 7, seance: 5, instant: A_18_20 - UNE_MINUTE })

        expect(message(monter()).exists()).toBe(false)
    })

    it('se date de la dernière réponse complète quand la prop n’a pas de date', () => {
        servir({ copieDu: A_18_20, datesDesProps: {} })
        noterUneEcritureAcceptee({ compte: 7, seance: 5, instant: A_18_20 + UNE_MINUTE })

        expect(message(monter()).text()).toContain('après 18:20')
    })

    it('ne dit rien d’une écriture d’une autre séance, ni d’un autre compte', () => {
        servir()
        noterUneEcritureAcceptee({ compte: 7, seance: 6, instant: A_18_20 + UNE_MINUTE })

        expect(message(monter()).exists()).toBe(false)

        noterUneEcritureAcceptee({ compte: 8, seance: 5, instant: A_18_20 + UNE_MINUTE })

        expect(message(monter()).exists()).toBe(false)
    })

    it('ne dit rien sans note, ni sur une page que le serveur a rendue', () => {
        servir()

        expect(message(monter()).exists()).toBe(false)

        etat.page.props = { auth: { user: { id: 7 } } }
        noterUneEcritureAcceptee({ compte: 7, seance: 5, instant: A_18_20 + UNE_MINUTE })

        expect(message(monter()).exists()).toBe(false)
    })

    it('ne dit rien d’une copie sans date lisible', () => {
        servir({ copieDu: 'hier', datesDesProps: {} })
        noterUneEcritureAcceptee({ compte: 7, seance: 5, instant: A_18_20 + UNE_MINUTE })

        expect(message(monter()).exists()).toBe(false)
    })
})

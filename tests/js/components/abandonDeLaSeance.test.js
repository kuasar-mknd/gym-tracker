import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ID_DE_L_ETAT_DE_LA_SEANCE } from '@/composables/useSeanceEnAttente'

const visite = vi.hoisted(() => ({ delete: vi.fn(), page: { props: { flash: {} } } }))

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal()),
    router: { delete: visite.delete },
    usePage: () => visite.page,
}))

import ActionsDeLaSeance from '@/Components/Workout/ActionsDeLaSeance.vue'
import { noterQueLeServeurNeRepondPas, noterQueLeServeurRepond } from '@/composables/useEtatDuReseau'
import { ECRITURES_ENCORE_EN_ROUTE, SEANCE_PLUS_VIDE } from '@/composables/useAbandonDeLaSeance'
import { ID_DE_LA_RAISON_DES_ECRITURES } from '@/composables/useCopieHorsLigne'
import SyncService from '@/Utils/SyncService'

/*
 * « Abandonner » (#2434, lot 5) : une séance sans exercice n'a rien à garder,
 * et « Terminer » n'est pas une sortie qu'on veuille (une séance vide close
 * reste dans l'historique). Le bouton ne se montre que sur une séance vide, la
 * question demande confirmation, puis la séance part par `workouts.destroy`.
 */
globalThis.route = (nom, parametres) => `/${nom}/${parametres?.workout ?? ''}`

const global = {
    directives: { press: {} },
    stubs: { Modal: { props: ['show'], template: '<div v-if="show"><slot /></div>' } },
}

const monter = (props = {}) => mount(ActionsDeLaSeance, { props: { vide: true, idDeLaSeance: 12, ...props }, global })
const abandonner = (wrapper) => wrapper.find('[dusk="abandonner-la-seance"]')
const confirmer = (wrapper) => wrapper.get('[dusk="confirm-dialog-confirm"]')

beforeEach(() => {
    visite.delete.mockReset()
    visite.page.props = { flash: {} }
    noterQueLeServeurRepond()
})

afterEach(() => vi.restoreAllMocks())

/** La suppression part après l'attente des écritures de la séance : une tâche, pas un tick. */
const attendreLaSuppression = () => vi.waitFor(() => expect(visite.delete).toHaveBeenCalledTimes(1))

describe('« Abandonner » sur une séance vide', () => {
    it('se montre seulement quand la séance n’a aucune ligne', () => {
        expect(abandonner(monter()).exists()).toBe(true)
        expect(abandonner(monter({ vide: false })).exists()).toBe(false)
    })

    it('reste hors de la barre d’outils : Exercice et Terminer seulement', () => {
        const barre = monter().get('[dusk="barre-d-outils-de-la-seance"]')

        expect(barre.find('[dusk="abandonner-la-seance"]').exists()).toBe(false)
        expect(barre.findAll('button').map((bouton) => bouton.attributes('dusk'))).toEqual([
            'add-exercise-existing',
            'finish-workout-mobile',
        ])
    })

    it('demande confirmation avant de supprimer, en tutoyant et sans rien envoyer', async () => {
        const wrapper = monter()

        expect(wrapper.find('[dusk="confirm-dialog-confirm"]').exists()).toBe(false)

        await abandonner(wrapper).trigger('click')

        expect(wrapper.text()).toContain('Abandonner cette séance ?')
        expect(wrapper.text()).toContain('Elle est vide : elle sera supprimée.')
        expect(confirmer(wrapper).text()).toBe('Abandonner')
        expect(wrapper.get('[dusk="confirm-dialog-cancel"]').text()).toBe('Annuler')
        expect(visite.delete).not.toHaveBeenCalled()
    })

    it('ne supprime rien quand on renonce', async () => {
        const wrapper = monter()

        await abandonner(wrapper).trigger('click')
        await wrapper.get('[dusk="confirm-dialog-cancel"]').trigger('click')

        expect(visite.delete).not.toHaveBeenCalled()
        expect(wrapper.find('[dusk="confirm-dialog-confirm"]').exists()).toBe(false)
    })

    it('supprime la séance par workouts.destroy une fois confirmé, la question refermée', async () => {
        const wrapper = monter()

        await abandonner(wrapper).trigger('click')
        await confirmer(wrapper).trigger('click')
        await attendreLaSuppression()

        expect(visite.delete.mock.calls[0][0]).toBe('/workouts.destroy/12')
        expect(wrapper.find('[dusk="confirm-dialog-confirm"]').exists()).toBe(false)
        expect(abandonner(wrapper).attributes('disabled')).toBeDefined()
    })

    it('se rouvre quand la suppression échoue : le geste redevient possible', async () => {
        const wrapper = monter()

        await abandonner(wrapper).trigger('click')
        await confirmer(wrapper).trigger('click')
        await attendreLaSuppression()

        const options = visite.delete.mock.calls[0][1]

        options.onBefore?.({})
        options.onHttpException?.({})
        options.onFinish?.({})
        await flushPromises()

        expect(abandonner(wrapper).attributes('disabled')).toBeUndefined()
    })

    /*
     * Une ligne ajoutée puis retirée laisse la séance vide à l'écran, et des
     * écritures en file : supprimée, la séance les verrait rejouées contre elle.
     */
    it('ne supprime pas tant que des écritures de la séance attendent, et le dit', async () => {
        vi.spyOn(SyncService, 'attendreLesEcritures').mockResolvedValue(undefined)
        vi.spyOn(SyncService, 'enAttente').mockReturnValue(2)
        const wrapper = monter()

        await abandonner(wrapper).trigger('click')
        await confirmer(wrapper).trigger('click')
        await vi.waitFor(() => expect(visite.page.props.flash.error).toBe(ECRITURES_ENCORE_EN_ROUTE))

        expect(visite.delete).not.toHaveBeenCalled()
        expect(abandonner(wrapper).attributes('disabled')).toBeUndefined()
    })

    /*
     * La file se vide pendant l'attente : tant qu'elle ne l'est pas, rien ne
     * part. Une promesse qu'on résout à la main tient l'attente ouverte.
     */
    it('ne supprime qu’une fois la file des écritures vidée, pas avant', async () => {
        let vider
        const file = new Promise((resolve) => (vider = resolve))
        let enAttente = 2
        vi.spyOn(SyncService, 'attendreLesEcritures').mockReturnValue(file)
        vi.spyOn(SyncService, 'enAttente').mockImplementation(() => enAttente)
        const wrapper = monter()

        await abandonner(wrapper).trigger('click')
        await confirmer(wrapper).trigger('click')
        await flushPromises()

        expect(visite.delete).not.toHaveBeenCalled()

        enAttente = 0
        vider()
        await attendreLaSuppression()

        expect(visite.delete.mock.calls[0][0]).toBe('/workouts.destroy/12')
    })

    /*
     * L'attente dure jusqu'à huit secondes : une ligne ajoutée entre-temps rend
     * la séance pleine, et le serveur supprimerait une séance qui a des données.
     */
    it('ne supprime pas une séance devenue non vide pendant l’attente, et verrouille l’ajout en attendant', async () => {
        let vider
        vi.spyOn(SyncService, 'attendreLesEcritures').mockReturnValue(new Promise((resolve) => (vider = resolve)))
        const wrapper = monter()
        const exercice = () => wrapper.get('[dusk="add-exercise-existing"]')

        expect(exercice().attributes('disabled')).toBeUndefined()

        await abandonner(wrapper).trigger('click')
        await confirmer(wrapper).trigger('click')
        await flushPromises()

        expect(exercice().attributes('disabled')).toBeDefined()
        expect(wrapper.get('[dusk="finish-workout-mobile"]').attributes('disabled')).toBeDefined()

        await wrapper.setProps({ vide: false })
        vider()
        await vi.waitFor(() => expect(visite.page.props.flash.error).toBe(SEANCE_PLUS_VIDE))

        expect(visite.delete).not.toHaveBeenCalled()
        expect(exercice().attributes('disabled')).toBeUndefined()
    })

    /* Une copie servie après le retour du réseau : la visite serait arrêtée, le bouton le dit d'avance. */
    it('ne part pas depuis une copie hors ligne, et vise la raison du bandeau', async () => {
        visite.page.props = { horsLigne: { copieDu: Date.now(), raison: 'reseau', datesDesProps: {} } }
        const wrapper = monter()

        expect(abandonner(wrapper).attributes('disabled')).toBeDefined()
        expect(abandonner(wrapper).attributes('aria-describedby')).toBe(ID_DE_LA_RAISON_DES_ECRITURES)

        await abandonner(wrapper).trigger('click')

        expect(wrapper.find('[dusk="confirm-dialog-confirm"]').exists()).toBe(false)
        expect(visite.delete).not.toHaveBeenCalled()
    })

    it('ne part pas sans réseau, et dit pourquoi', async () => {
        noterQueLeServeurNeRepondPas()
        const wrapper = monter()

        expect(abandonner(wrapper).attributes('disabled')).toBeDefined()
        expect(abandonner(wrapper).attributes('aria-describedby')).toBe('abandon-sans-reseau')
        expect(wrapper.get('#abandon-sans-reseau').text()).toBe('Il faut le réseau pour abandonner la séance.')

        await abandonner(wrapper).trigger('click')

        expect(wrapper.find('[dusk="confirm-dialog-confirm"]').exists()).toBe(false)
        expect(visite.delete).not.toHaveBeenCalled()
    })

    it('ne part pas d’une séance démarrée sans réseau, que le serveur ne connaît pas encore', async () => {
        const wrapper = monter({ seanceEnAttente: true, idDeLaSeance: 'temp-1' })

        expect(abandonner(wrapper).attributes('disabled')).toBeDefined()
        expect(abandonner(wrapper).attributes('aria-describedby')).toBe(ID_DE_L_ETAT_DE_LA_SEANCE)

        await abandonner(wrapper).trigger('click')

        expect(visite.delete).not.toHaveBeenCalled()
    })

    it('laisse « Modèle » à la séance qui a des exercices', () => {
        const wrapper = monter({ vide: false })

        expect(wrapper.find('[dusk="enregistrer-comme-modele"]').exists()).toBe(true)
        expect(wrapper.find('#abandon-sans-reseau').exists()).toBe(false)
    })
})

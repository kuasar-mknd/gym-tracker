import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { h, reactive, ref } from 'vue'

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    patch: vi.fn(),
    visit: vi.fn(),
    flash: vi.fn(),
    formulaire: null,
    page: null,
}))
const haptics = vi.hoisted(() => ({ triggerHaptic: vi.fn() }))
const file = vi.hoisted(() => ({
    patch: vi.fn(),
    mettreEnFile: vi.fn(),
    attendreLesEcritures: vi.fn(),
    attendreLesEcrituresDirectes: vi.fn(),
    enAttente: 0,
    ecrituresEnCours: 0,
}))
vi.mock('@inertiajs/vue3', () => ({
    router: { post: inertia.post, patch: inertia.patch, visit: inertia.visit, flash: inertia.flash },
    useForm: (champs) => {
        inertia.formulaire = { ...champs, transform: vi.fn(), patch: vi.fn() }
        inertia.formulaire.transform.mockReturnValue(inertia.formulaire)

        return inertia.formulaire
    },
    // Les réglages se recalent à la sortie d'une copie hors ligne : la page du serveur ici (#2048).
    usePage: () => inertia.page,
}))
vi.mock('@/composables/useHaptics', () => haptics)
vi.mock('@/Utils/SyncService', () => ({
    default: {
        patch: (...args) => file.patch(...args),
        mettreEnFile: (...args) => file.mettreEnFile(...args),
        attendreLesEcritures: (...args) => file.attendreLesEcritures(...args),
        attendreLesEcrituresDirectes: (...args) => file.attendreLesEcrituresDirectes(...args),
        enAttente: () => file.enAttente,
        ecrituresEnCours: () => file.ecrituresEnCours,
    },
}))

import { useReglagesDeLaSeance } from '@/composables/useReglagesDeLaSeance'
import { FIN_NON_ENREGISTREE } from '@/Utils/seanceEnAttente'
import { noterQueLeServeurNeRepondPas, noterQueLeServeurRepond } from '@/composables/useEtatDuReseau'
import { PendingIds } from '@/Utils/pendingIds'

const HEURE_DU_GESTE = new Date('2026-10-19T18:42:00.000Z')

const monter = ({ seance = {}, pendingIds } = {}) => {
    const viderLesEcritures = vi.fn().mockResolvedValue(undefined)
    const localWorkout = ref({
        id: 5,
        name: 'Jambes',
        started_at: '2026-09-05T10:00:00.000000Z',
        ended_at: null,
        notes: null,
        ...seance,
    })

    return {
        viderLesEcritures,
        localWorkout,
        ...useReglagesDeLaSeance({ localWorkout, viderLesEcritures, pendingIds }),
    }
}

/** La fin acceptée par le serveur, telle que l'API la rend. */
const finAcceptee = (meta = {}) => ({
    data: {
        data: { id: 5, ended_at: HEURE_DU_GESTE.toISOString() },
        meta: { proposer_les_notifications: false, ...meta },
    },
})

beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(HEURE_DU_GESTE)
    globalThis.route = (nom, params = {}) => `/${nom}/${Object.values(params).join('/')}`
    inertia.page = reactive({ props: {} })
    file.attendreLesEcritures.mockResolvedValue(undefined)
    file.attendreLesEcrituresDirectes.mockResolvedValue(undefined)
    file.mettreEnFile.mockReturnValue('qF')
    file.patch.mockResolvedValue(finAcceptee())
    file.enAttente = 0
    file.ecrituresEnCours = 0
    noterQueLeServeurRepond()
})

afterEach(() => {
    vi.useRealTimers()
})

describe('les réglages de la séance', () => {
    it('enregistre la séance comme modèle, le bouton occupé le temps de l’aller-retour', () => {
        const reglages = monter()

        reglages.saveAsTemplate()
        expect(reglages.savingTemplate.value).toBe(true)
        expect(inertia.post).toHaveBeenCalledWith(
            '/templates.save-from-workout/5',
            {},
            expect.objectContaining({ preserveScroll: true }),
        )

        inertia.post.mock.calls[0][2].onFinish()
        expect(reglages.savingTemplate.value).toBe(false)
    })

    it('vide les écritures en attente, puis termine la séance par l’API, à l’heure du geste, et mène à l’accueil', async () => {
        const reglages = monter()
        reglages.finishWorkout()
        expect(reglages.showFinishModal.value).toBe(true)

        await reglages.confirmFinishWorkout()

        expect(reglages.viderLesEcritures).toHaveBeenCalledTimes(1)
        expect(file.patch).toHaveBeenCalledWith(
            '/api.v1.workouts.finish/',
            { workout_id: 5, ended_at: HEURE_DU_GESTE.toISOString() },
            { delaiDeLaTentative: 5000 },
        )
        expect(reglages.viderLesEcritures.mock.invocationCallOrder[0]).toBeLessThan(
            file.patch.mock.invocationCallOrder[0],
        )
        expect(inertia.patch).not.toHaveBeenCalled()
        expect(reglages.showFinishModal.value).toBe(false)
        expect(reglages.clotureEnCours.value).toBe(false)
        expect(reglages.localWorkout.value.ended_at).toBe(HEURE_DU_GESTE.toISOString())
        expect(haptics.triggerHaptic).toHaveBeenCalledWith('success')
        expect(inertia.visit).toHaveBeenCalledWith('/dashboard/', expect.any(Object))

        // Sans invitation dans la réponse, l'accueil ne la montre pas.
        inertia.visit.mock.calls[0][1].onSuccess()
        expect(inertia.flash).not.toHaveBeenCalled()
    })

    /*
     * L'invitation aux notifications passe par la page (#2049) : l'API ne pose
     * aucune donnée flash dans la session, qu'un rejeu de la file ferait surgir
     * sur une page au hasard.
     */
    it('pose l’invitation aux notifications à l’arrivée sur l’accueil quand la réponse le dit', async () => {
        file.patch.mockResolvedValue(finAcceptee({ proposer_les_notifications: true }))
        const reglages = monter()

        await reglages.confirmFinishWorkout()

        expect(inertia.flash).not.toHaveBeenCalled()

        inertia.visit.mock.calls[0][1].onSuccess()

        expect(inertia.flash).toHaveBeenCalledWith('proposerLesNotifications', true)
    })

    it('prend l’heure de fin au « Confirmer », avant d’attendre les écritures', async () => {
        file.attendreLesEcritures.mockImplementation(async () => {
            vi.setSystemTime(new Date('2026-10-19T18:42:07.000Z'))
        })
        const reglages = monter()

        await reglages.confirmFinishWorkout()

        expect(file.patch.mock.calls[0][1].ended_at).toBe(HEURE_DU_GESTE.toISOString())
    })

    /*
     * La question restait ouverte et disait que la fin n'était pas confirmée,
     * la séance ouverte à l'écran (#2048). La fin attend désormais la file
     * (#2049) : la question se ferme, la séance se montre close.
     */
    it('met la fin en file quand elle part sans réponse : la question se ferme, la séance se montre close', async () => {
        file.patch.mockRejectedValue({ isOffline: true, queueId: 'qF' })
        const reglages = monter()
        reglages.finishWorkout()

        await reglages.confirmFinishWorkout()

        expect(reglages.showFinishModal.value).toBe(false)
        expect(reglages.clotureEnCours.value).toBe(false)
        expect(reglages.localWorkout.value).toMatchObject({
            ended_at: HEURE_DU_GESTE.toISOString(),
            _finEnAttente: 'qF',
        })
        expect(haptics.triggerHaptic).toHaveBeenCalledWith('success')
        expect(inertia.visit).not.toHaveBeenCalled()

        // Close à l'écran : un nouvel appui ne met pas une seconde fin en file.
        await reglages.confirmFinishWorkout()

        expect(file.patch).toHaveBeenCalledTimes(1)
    })

    it('met la fin en file sans la tenter quand le serveur ne répond pas, et n’attend que les écritures en vol', async () => {
        noterQueLeServeurNeRepondPas()
        const reglages = monter()

        await reglages.confirmFinishWorkout()

        expect(file.attendreLesEcrituresDirectes).toHaveBeenCalledTimes(1)
        expect(file.attendreLesEcritures).not.toHaveBeenCalled()
        expect(file.patch).not.toHaveBeenCalled()
        expect(file.mettreEnFile).toHaveBeenCalledWith({
            method: 'patch',
            url: '/api.v1.workouts.finish/',
            data: { workout_id: 5, ended_at: HEURE_DU_GESTE.toISOString() },
        })
        expect(reglages.localWorkout.value._finEnAttente).toBe('qF')
    })

    it('met la fin en file derrière ce qui attend encore, sans la tenter', async () => {
        file.enAttente = 2
        const reglages = monter()

        await reglages.confirmFinishWorkout()

        expect(file.attendreLesEcritures).toHaveBeenCalledTimes(1)
        expect(file.patch).not.toHaveBeenCalled()
        expect(file.mettreEnFile).toHaveBeenCalledTimes(1)
        expect(reglages.localWorkout.value._finEnAttente).toBe('qF')
    })

    it('nomme une séance démarrée sans réseau par l’entrée de sa création', async () => {
        const pendingIds = new PendingIds()
        pendingIds.track('temp-qS', new Promise(() => {}))
        pendingIds.noterEnFile('temp-qS', 'qS')
        const reglages = monter({ seance: { id: 'temp-qS' }, pendingIds })

        await reglages.confirmFinishWorkout()

        expect(file.patch).not.toHaveBeenCalled()
        expect(file.mettreEnFile.mock.calls[0][0].data).toEqual({
            workout_id: { enAttenteDe: 'qS' },
            ended_at: HEURE_DU_GESTE.toISOString(),
        })
    })

    it('ne clôt pas tant qu’une écriture vole encore : la question le dit', async () => {
        file.ecrituresEnCours = 1
        const reglages = monter()
        reglages.finishWorkout()

        await reglages.confirmFinishWorkout()

        expect(reglages.ecrituresEnAttente.value).toBe(1)
        expect(reglages.showFinishModal.value).toBe(true)
        expect(file.patch).not.toHaveBeenCalled()
        expect(file.mettreEnFile).not.toHaveBeenCalled()
        expect(reglages.localWorkout.value.ended_at).toBeNull()
    })

    it('dit le refus du serveur par un toast, et la séance reste ouverte', async () => {
        file.patch.mockRejectedValue({ response: { status: 422, data: { message: 'workout_id' } } })
        const reglages = monter()
        reglages.finishWorkout()

        await reglages.confirmFinishWorkout()

        expect(reglages.showFinishModal.value).toBe(false)
        expect(inertia.page.props.flash.error).toBe(FIN_NON_ENREGISTREE)
        expect(haptics.triggerHaptic).toHaveBeenCalledWith('error')
        expect(reglages.localWorkout.value.ended_at).toBeNull()
        expect(inertia.visit).not.toHaveBeenCalled()
    })

    it('dit aussi la fin que la file ne peut pas garder', async () => {
        noterQueLeServeurNeRepondPas()
        file.mettreEnFile.mockReturnValue(null)
        const reglages = monter()

        await reglages.confirmFinishWorkout()

        expect(inertia.page.props.flash.error).toBe(FIN_NON_ENREGISTREE)
        expect(reglages.localWorkout.value.ended_at).toBeNull()
    })

    it('envoie les réglages avec le début en UTC, et referme la modale quand le serveur accepte', () => {
        const reglages = monter()
        reglages.showSettingsModal.value = true
        expect(reglages.settingsForm.name).toBe('Jambes')
        expect(reglages.settingsForm.notes).toBe('')

        reglages.updateSettings()

        const transformer = inertia.formulaire.transform.mock.calls[0][0]
        // Un autre jour que celui de la séance : l'heure affichée dépend du fuseau
        // de la machine, et une saisie qui lui serait égale ne serait pas envoyée.
        expect(transformer({ name: 'Dos', started_at: '2026-09-07T12:00' }).started_at).toMatch(/Z$|\+00:00$/)
        expect(inertia.formulaire.patch).toHaveBeenCalledWith(
            '/workouts.update/5',
            expect.objectContaining({ preserveScroll: true }),
        )

        inertia.formulaire.patch.mock.calls[0][1].onSuccess()
        expect(reglages.showSettingsModal.value).toBe(false)
    })

    it('n’envoie pas le début quand l’heure affichée n’a pas changé, pour ne pas en perdre les secondes', () => {
        const reglages = monter({ seance: { started_at: '2026-10-05T08:30:27.000000Z' } })

        reglages.updateSettings()

        const transformer = inertia.formulaire.transform.mock.calls[0][0]
        const envoye = transformer({ name: 'Dos', started_at: reglages.settingsForm.started_at, notes: '' })

        expect(envoye).toEqual({ name: 'Dos', notes: '' })
        expect(transformer({ name: 'Dos', started_at: '2026-10-05T08:31', notes: '' })).toHaveProperty('started_at')
    })
})

/*
 * La fin que la séance attend dans la file (#2049), la page restée ouverte :
 * rejouée, elle se confirme ; refusée ou retirée, la séance se rouvre, et un
 * refus se dit, sauf celui que la séance démarrée sans réseau emporte.
 */
describe('la fin en file, suivie par la page', () => {
    const monterSurLaPage = (seance = {}) => {
        let reglages

        const wrapper = mount({
            setup() {
                reglages = monter({ seance: { ended_at: '2026-10-19T18:42:00.000Z', _finEnAttente: 'qF', ...seance } })

                return () => h('div')
            },
        })

        return { wrapper, reglages }
    }

    const annoncer = async (type, detail) => {
        window.dispatchEvent(new CustomEvent(type, { detail }))
        await flushPromises()
    }

    const FIN = '/api/v1/workouts/finish'

    it('se confirme au rejeu, à l’heure que le serveur a gardée', async () => {
        const { wrapper, reglages } = monterSurLaPage()

        await annoncer('sync:replayed', { queueId: 'autre', url: FIN, data: { id: 5, ended_at: 'x' } })

        expect(reglages.localWorkout.value._finEnAttente).toBe('qF')

        await annoncer('sync:replayed', {
            queueId: 'qF',
            url: FIN,
            data: { id: 5, ended_at: '2026-10-19T18:42:30.000Z' },
        })

        expect(reglages.localWorkout.value).not.toHaveProperty('_finEnAttente')
        expect(reglages.localWorkout.value.ended_at).toBe('2026-10-19T18:42:30.000Z')
        wrapper.unmount()
    })

    it('rouvre la séance que le serveur a refusé de clore, et le dit', async () => {
        const { wrapper, reglages } = monterSurLaPage()

        await annoncer('sync:failed', { queueId: 'qF', method: 'patch', url: FIN, status: 422 })

        expect(reglages.localWorkout.value.ended_at).toBeNull()
        expect(reglages.localWorkout.value).not.toHaveProperty('_finEnAttente')
        expect(inertia.page.props.flash.error).toBe(FIN_NON_ENREGISTREE)
        wrapper.unmount()
    })

    it('rouvre sans un mot la séance dont la création est refusée, ou dont la fin sort de la file', async () => {
        const refusee = monterSurLaPage()

        await annoncer('sync:failed', { queueId: 'qF', method: 'patch', url: FIN, parentRefuse: true, parent: 'qS' })

        expect(refusee.reglages.localWorkout.value.ended_at).toBeNull()
        expect(inertia.page.props.flash).toBeUndefined()
        refusee.wrapper.unmount()

        const retiree = monterSurLaPage()

        await annoncer('sync:retired', { queueIds: ['qX'] })

        expect(retiree.reglages.localWorkout.value._finEnAttente).toBe('qF')

        await annoncer('sync:retired', { queueIds: ['qF'] })

        expect(retiree.reglages.localWorkout.value.ended_at).toBeNull()
        expect(inertia.page.props.flash).toBeUndefined()
        retiree.wrapper.unmount()
    })

    it('n’écoute plus rien une fois la page partie', async () => {
        const { wrapper, reglages } = monterSurLaPage()
        wrapper.unmount()

        await annoncer('sync:failed', { queueId: 'qF', method: 'patch', url: FIN, status: 422 })

        expect(reglages.localWorkout.value._finEnAttente).toBe('qF')
    })
})

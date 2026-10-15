import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
import ActionsDeLaSeance from '@/Components/Workout/ActionsDeLaSeance.vue'
import WorkoutFinishModal from '@/Components/Workout/WorkoutFinishModal.vue'
import WorkoutSettingsModal from '@/Components/Workout/WorkoutSettingsModal.vue'
import { noterQueLeServeurNeRepondPas, noterQueLeServeurRepond } from '@/composables/useEtatDuReseau'

/*
 * Ce que la séance ouverte fait des gestes qui ont besoin du serveur, quand il
 * ne répond pas (#2048) : les réglages et « Modèle » se désactivent et disent
 * pourquoi, et une clôture partie sans réseau garde la question ouverte.
 */

const modaleSansFenetre = { Modal: { template: '<div><slot /></div>' } }

beforeEach(() => {
    noterQueLeServeurRepond()
})

describe('les actions sous les exercices', () => {
    const monter = () => mount(ActionsDeLaSeance, { global: { directives: { press: {} } } })
    const bouton = (wrapper, dusk) => wrapper.find(`[dusk="${dusk}"]`)

    it('renvoie chaque geste à la page', async () => {
        const wrapper = monter()

        await bouton(wrapper, 'add-exercise-existing').trigger('click')
        await bouton(wrapper, 'open-rest-timer').trigger('click')
        await bouton(wrapper, 'enregistrer-comme-modele').trigger('click')
        await bouton(wrapper, 'finish-workout-mobile').trigger('click')

        expect(Object.keys(wrapper.emitted())).toEqual(
            expect.arrayContaining([
                'ajouter-un-exercice',
                'demarrer-un-repos',
                'enregistrer-comme-modele',
                'terminer',
            ]),
        )
        expect(wrapper.find('#modele-sans-reseau').exists()).toBe(false)
    })

    it('désactive « Modèle » sans réseau, et dit pourquoi ; « Terminer » reste', async () => {
        noterQueLeServeurNeRepondPas()
        const wrapper = monter()
        const modele = bouton(wrapper, 'enregistrer-comme-modele')

        expect(modele.attributes('disabled')).toBeDefined()
        expect(modele.attributes('aria-describedby')).toBe('modele-sans-reseau')
        expect(wrapper.find('#modele-sans-reseau').text()).toBe(
            'Il faut le réseau pour enregistrer la séance comme modèle.',
        )
        expect(bouton(wrapper, 'finish-workout-mobile').attributes('disabled')).toBeUndefined()

        await modele.trigger('click')

        expect(wrapper.emitted('enregistrer-comme-modele')).toBeUndefined()

        noterQueLeServeurRepond()
        await wrapper.vm.$nextTick()

        expect(modele.attributes('disabled')).toBeUndefined()
    })
})

describe('les réglages de la séance', () => {
    const formulaire = () => reactive({ name: 'Jambes', started_at: '', notes: '', errors: {}, processing: false })
    const monter = () =>
        mount(WorkoutSettingsModal, {
            props: { show: true, form: formulaire() },
            global: { directives: { press: {} }, stubs: modaleSansFenetre },
        })

    it('s’envoient quand le serveur répond', async () => {
        const wrapper = monter()

        await wrapper.find('form').trigger('submit')

        expect(wrapper.emitted('submit')).toHaveLength(1)
        expect(wrapper.find('#reglages-sans-reseau').exists()).toBe(false)
    })

    it('ne s’envoient pas sans réseau, « Enregistrer » désactivé avec sa raison', async () => {
        noterQueLeServeurNeRepondPas()
        const wrapper = monter()
        const enregistrer = wrapper.find('[dusk="save-settings-button"]')

        expect(enregistrer.attributes('disabled')).toBeDefined()
        expect(enregistrer.attributes('aria-describedby')).toBe('reglages-sans-reseau')
        expect(wrapper.find('#reglages-sans-reseau').text()).toBe(
            'Il faut le réseau pour enregistrer les réglages de la séance.',
        )

        // La touche Entrée dans un champ soumet le formulaire sans passer par le bouton.
        await wrapper.find('form').trigger('submit')

        expect(wrapper.emitted('submit')).toBeUndefined()
    })
})

describe('la question de fin', () => {
    it('dit que la fin de la séance n’est pas confirmée quand la clôture est partie sans réseau', () => {
        const wrapper = mount(WorkoutFinishModal, {
            props: { show: true, sansReseau: true },
            global: { stubs: modaleSansFenetre },
        })

        expect(wrapper.find('[dusk="finish-workout-offline"]').text()).toBe(
            'Pas de réponse du serveur : la fin de la séance n’est pas confirmée. Réessaie quand le réseau sera revenu.',
        )
        expect(wrapper.find('[dusk="finish-workout-offline"]').attributes('role')).toBe('status')
    })

    it('n’en dit rien pendant la nouvelle tentative, ni sans échec', () => {
        const enCours = mount(WorkoutFinishModal, {
            props: { show: true, sansReseau: true, enCours: true },
            global: { stubs: modaleSansFenetre },
        })
        const sansEchec = mount(WorkoutFinishModal, { props: { show: true }, global: { stubs: modaleSansFenetre } })

        expect(enCours.find('[dusk="finish-workout-offline"]').exists()).toBe(false)
        expect(sansEchec.find('[dusk="finish-workout-offline"]').exists()).toBe(false)
    })
})

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import BilanDeFinDeSeance from '@/Components/Workout/BilanDeFinDeSeance.vue'
import WorkoutFinishModal from '@/Components/Workout/WorkoutFinishModal.vue'

/*
 * Le bilan de fin de séance (#2434) : il s'ouvre APRÈS la fin, sur la page de la
 * séance close. Durée, volume et séries viennent de la copie locale ; les records
 * et « Reprendre » (quinze minutes) viennent du serveur ; hors ligne, il dit
 * « Enregistrée sur cet appareil » et n'offre ni l'un ni l'autre.
 */
const SEANCE = {
    started_at: '2026-11-19T17:06:00.000Z',
    ended_at: '2026-11-19T18:00:00.000Z',
    workout_lines: [
        {
            sets: [
                { is_completed: true, weight: '82.50', reps: 8 },
                { is_completed: true, weight: 82.5, reps: 8, is_warmup: false },
                { is_completed: false, weight: 90, reps: 5 },
            ],
        },
        { sets: [{ is_completed: true, weight: null, reps: 12 }] },
    ],
}

const DU_SERVEUR = {
    enAttente: false,
    records: [
        { exercice: 'Développé couché', valeur: 102.5 },
        { exercice: 'Squat', valeur: 140 },
    ],
    peutReprendre: true,
    repriseJusqua: '2026-11-19T18:15:00.000Z',
    proposer: false,
}

const EN_FILE = { enAttente: true, records: [], peutReprendre: false, repriseJusqua: null, proposer: false }

const monter = (props = {}) =>
    mount(BilanDeFinDeSeance, {
        props: { bilan: DU_SERVEUR, seance: SEANCE, ...props },
        global: { stubs: { Modal: { props: ['show'], template: '<div v-if="show"><slot /></div>' } } },
    })

const lire = (wrapper, dusk) => wrapper.get(`[dusk="${dusk}"]`).text()
const boutons = (wrapper) => wrapper.findAll('button').map((bouton) => bouton.text())

describe('le bilan de fin de séance', () => {
    it('reste fermé tant que la fin n’est pas partie', () => {
        const wrapper = monter({ bilan: null })

        expect(wrapper.find('[dusk="bilan-de-fin"]').exists()).toBe(false)
    })

    it('dit la durée, le volume des séries validées et leur nombre', () => {
        const wrapper = monter()

        expect(lire(wrapper, 'finish-workout-duree')).toBe('54 min')
        // 82,5 × 8 deux fois ; la série décochée et celle sans poids ne comptent pas.
        expect(lire(wrapper, 'finish-workout-volume').replace(/\s/g, ' ')).toMatch(/^1\D?320 kg$/)
        expect(lire(wrapper, 'finish-workout-series')).toBe('3')
    })

    it('écrit une durée d’une heure ou plus en heures et minutes', () => {
        const wrapper = monter({ seance: { ...SEANCE, started_at: '2026-11-19T16:56:00.000Z' } })

        expect(lire(wrapper, 'finish-workout-duree')).toBe('1 h 04')
    })

    it('mesure une séance oubliée à la fin que la personne a choisie', () => {
        const wrapper = monter({
            seance: { ...SEANCE, started_at: '2026-11-17T09:00:00.000Z', ended_at: '2026-11-17T10:30:00.000Z' },
        })

        expect(lire(wrapper, 'finish-workout-duree')).toBe('1 h 30')
    })

    it('ne compte aucun volume ni série pour une séance sans série cochée', () => {
        const wrapper = monter({ seance: { ...SEANCE, workout_lines: [{ sets: [{ is_completed: false }] }] } })

        expect(lire(wrapper, 'finish-workout-volume')).toBe('0 kg')
        expect(lire(wrapper, 'finish-workout-series')).toBe('0')
    })

    it('tient sans séance', () => {
        const wrapper = monter({ seance: null })

        expect(lire(wrapper, 'finish-workout-duree')).toBe('—')
        expect(lire(wrapper, 'finish-workout-series')).toBe('0')
    })

    it('liste les records de poids que le serveur donne', () => {
        const wrapper = monter()
        const lignes = wrapper.findAll('[dusk="bilan-de-fin-records"] li').map((ligne) => ligne.text())

        expect(lignes).toHaveLength(2)
        expect(lignes[0]).toMatch(/^Développé couché\s*102[,.]5 kg$/)
        expect(lignes[1]).toMatch(/^Squat\s*140 kg$/)
    })

    it('n’ouvre aucune section de records quand il n’y en a pas', () => {
        const wrapper = monter({ bilan: { ...DU_SERVEUR, records: [] } })

        expect(wrapper.find('[dusk="bilan-de-fin-records"]').exists()).toBe(false)
    })

    it('offre « Reprendre » et « Fermer », et ne dit plus qu’une séance enregistrée ne se rouvre pas', async () => {
        const wrapper = monter()

        expect(boutons(wrapper)).toEqual(['Fermer', 'Reprendre'])
        expect(wrapper.text()).not.toContain('ne se rouvre')
        expect(wrapper.text()).not.toMatch(/appareil/i)

        await wrapper.get('[dusk="bilan-de-fin-reprendre"]').trigger('click')
        await wrapper.get('[dusk="bilan-de-fin-fermer"]').trigger('click')

        expect(wrapper.emitted('reprendre')).toHaveLength(1)
        expect(wrapper.emitted('fermer')).toHaveLength(1)
    })

    it('n’offre plus « Reprendre » quand le serveur ne le permet plus (fin trop ancienne)', () => {
        const wrapper = monter({ bilan: { ...DU_SERVEUR, peutReprendre: false } })

        expect(boutons(wrapper)).toEqual(['Fermer'])
    })

    it('dit en une phrase pourquoi « Reprendre » n’est plus offert', () => {
        const wrapper = monter({ bilan: { ...DU_SERVEUR, peutReprendre: false, reprisePerimee: true } })

        expect(lire(wrapper, 'bilan-de-fin-reprise-perimee')).toBe('Le délai pour reprendre est passé.')
        expect(boutons(wrapper)).toEqual(['Fermer'])
        expect(monter().find('[dusk="bilan-de-fin-reprise-perimee"]').exists()).toBe(false)
    })

    it('occupe « Reprendre » le temps de l’aller-retour, et retient « Fermer »', () => {
        const wrapper = monter({ enCours: true })

        expect(wrapper.get('[dusk="bilan-de-fin-fermer"]').attributes('disabled')).toBeDefined()
    })

    it('sans réseau, dit « Enregistrée sur cet appareil », sans records ni « Reprendre »', () => {
        const wrapper = monter({ bilan: { ...EN_FILE, records: [{ exercice: 'Squat', valeur: 140 }] } })

        expect(lire(wrapper, 'bilan-de-fin-sur-l-appareil')).toBe('Enregistrée sur cet appareil')
        expect(wrapper.find('[dusk="bilan-de-fin-records"]').exists()).toBe(false)
        expect(boutons(wrapper)).toEqual(['Fermer'])
        expect(lire(wrapper, 'finish-workout-series')).toBe('3')
    })

    it('ne dit rien du réseau quand le serveur a enregistré la fin', () => {
        const wrapper = monter()

        expect(wrapper.text()).not.toMatch(/réseau|appareil|hors ligne/i)
    })
})

describe('la question « Terminer la séance ? » reste simple', () => {
    it('ne porte plus de chiffres du bilan : le bilan vient après la fin', () => {
        const wrapper = mount(WorkoutFinishModal, {
            props: { show: true, seance: SEANCE },
            global: { stubs: { Modal: { template: '<div><slot /></div>' } } },
        })

        expect(wrapper.find('[dusk="finish-workout-bilan"]').exists()).toBe(false)
        expect(wrapper.findAll('button').map((bouton) => bouton.text())).toEqual(['Annuler', 'Confirmer'])
    })
})

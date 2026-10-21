import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

import EtatDeLaSeanceEnAttente from '@/Components/Workout/EtatDeLaSeanceEnAttente.vue'
import { ETAT_EN_ATTENTE, ETAT_REFUSEE, ID_DE_L_ETAT_DE_LA_SEANCE } from '@/composables/useSeanceEnAttente'
import { SEANCE_EN_ATTENTE, SEANCE_REFUSEE, messageDeLaFinEnAttente } from '@/Utils/seanceEnAttente'

/*
 * Ce que dit la séance que le serveur ne connaît pas encore tout entière
 * (#2049) : sa création en file, son refus, et sa fin en file, à l'heure du
 * geste, avec un lien vers l'accueil.
 */

const FIN = '2026-10-19T18:42:00.000Z'

const monter = (props) =>
    mount(EtatDeLaSeanceEnAttente, {
        props,
        global: {
            stubs: { Link: { props: ['href'], template: '<a :href="href"><slot /></a>' }, GlassIcon: true },
            mocks: { route: (nom) => `/${nom}` },
        },
    })

const accueil = (wrapper) => wrapper.find('[dusk="seance-en-attente-accueil"]')

beforeEach(() => {
    document.body.innerHTML = ''
})

describe('l’état d’une séance que le serveur ne connaît pas encore', () => {
    it('dit la fin en file d’une séance du serveur, à l’heure du geste, avec un lien vers l’accueil', () => {
        const wrapper = monter({ etat: null, seance: { id: 5, ended_at: FIN, _finEnAttente: 'qF' } })

        expect(wrapper.get(`#${ID_DE_L_ETAT_DE_LA_SEANCE}`).text()).toBe(messageDeLaFinEnAttente(FIN))
        expect(wrapper.get('[role="status"]').classes()).toContain('info-fill')
        expect(accueil(wrapper).attributes('href')).toBe('/dashboard')
    })

    it('dit la fin plutôt que la création qui attend, sur une séance démarrée sans réseau', () => {
        const enAttente = monter({ etat: ETAT_EN_ATTENTE, seance: { id: 'temp-qS' } })
        const terminee = monter({
            etat: ETAT_EN_ATTENTE,
            seance: { id: 'temp-qS', ended_at: FIN, _finEnAttente: 'qF' },
        })

        expect(enAttente.text()).toContain(SEANCE_EN_ATTENTE)
        expect(accueil(enAttente).exists()).toBe(false)
        expect(terminee.text()).toContain(messageDeLaFinEnAttente(FIN))
        expect(accueil(terminee).exists()).toBe(true)
    })

    it('dit le refus de la séance plutôt que sa fin, qui ne partira pas sans elle', () => {
        const wrapper = monter({ etat: ETAT_REFUSEE, seance: { id: 'temp-qS', ended_at: FIN, _finEnAttente: 'qF' } })

        expect(wrapper.text()).toContain(SEANCE_REFUSEE)
        expect(wrapper.text()).not.toContain('Séance terminée')
    })

    it('ne dit rien d’une séance du serveur, ouverte ou close par lui', () => {
        expect(monter({ etat: null, seance: { id: 5, ended_at: null } }).html()).toBe('<!--v-if-->')
        expect(monter({ etat: null, seance: { id: 5, ended_at: FIN } }).html()).toBe('<!--v-if-->')
    })
})

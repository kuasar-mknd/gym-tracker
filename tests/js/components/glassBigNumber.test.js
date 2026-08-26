import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import GlassBigNumber from '@/Components/UI/GlassBigNumber.vue'

const monter = (props = {}, options = {}) =>
    mount(GlassBigNumber, { props: { label: 'Poids Cible', ...props }, ...options })

const champ = (wrapper) => wrapper.get('input')

describe('GlassBigNumber', () => {
    /**
     * `v-model` sur un `<input type="number">` caste tout seul ; un composant
     * qui réémet `event.target.value` perd ce cast, et les calculateurs se
     * mettent à comparer des chaînes. C'est arrivé, et la barre s'est affichée
     * vide.
     */
    it('rend un nombre, pas la chaîne que le DOM contient', async () => {
        const wrapper = monter()

        await champ(wrapper).setValue('62.5')

        expect(wrapper.emitted('update:modelValue')[0]).toEqual([62.5])
    })

    /** Un champ vidé n'est pas un zéro : `Number('')` vaut 0, et 0 kg est une valeur. */
    it('distingue un champ vide de zéro', async () => {
        const wrapper = monter()

        await champ(wrapper).setValue('')

        expect(wrapper.emitted('update:modelValue')[0]).toEqual([''])
    })

    /**
     * Un `<input type="number">` filtre lui-même : « - » n'atteint jamais le
     * modèle, il laisse simplement `value` à vide. C'est ce qui rend inutile
     * tout repli sur la chaîne brute — et cette assertion est là pour que le
     * jour où ce n'est plus vrai, on l'apprenne ici.
     */
    it('ne laisse pas passer une saisie que le champ lui-même refuse', async () => {
        const wrapper = monter()

        await champ(wrapper).setValue('-')

        expect(wrapper.emitted('update:modelValue')[0]).toEqual([''])
    })

    /**
     * Le libellé doit désigner le champ. Les six champs remplacés portaient un
     * `id` écrit à la main dans DEUX endroits — le `for` et l'`id` —, et il
     * suffisait d'en renommer un.
     */
    it('relie son libellé au champ, avec ou sans identifiant fourni', () => {
        const fourni = monter({}, { attrs: { id: 'poids-cible' } })

        expect(fourni.get('label').attributes('for')).toBe('poids-cible')
        expect(champ(fourni).attributes('id')).toBe('poids-cible')

        const auto = monter()

        expect(auto.get('label').attributes('for')).toBe(champ(auto).attributes('id'))
        expect(champ(auto).attributes('id')).toBeTruthy()
    })

    /** L'unité redit ce que le libellé annonce déjà : à l'œil seulement. */
    it("montre l'unité sans la faire lire deux fois", () => {
        const avec = monter({ unite: 'kg' })

        expect(avec.get('span').text()).toBe('kg')
        expect(avec.get('span').attributes('aria-hidden')).toBe('true')
        expect(champ(avec).classes()).toContain('pr-14')

        const sans = monter()

        expect(sans.find('span').exists()).toBe(false)
        expect(champ(sans).classes()).not.toContain('pr-14')
    })

    it("transmet les bornes de saisie que l'appelant pose", () => {
        const wrapper = monter({}, { attrs: { min: '1', max: '100', step: '0.5', placeholder: '100' } })

        expect(champ(wrapper).attributes()).toMatchObject({
            type: 'number',
            min: '1',
            max: '100',
            step: '0.5',
            placeholder: '100',
        })
    })
})

import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import GlassTextarea from '@/Components/UI/GlassTextarea.vue'

const monter = (props = {}) => mount(GlassTextarea, { props: { modelValue: '', label: 'Description', ...props } })

describe('GlassTextarea', () => {
    it('relie l’étiquette au champ et remonte la saisie', async () => {
        const wrapper = monter()
        const champ = wrapper.get('textarea')

        expect(wrapper.get('label').attributes('for')).toBe(champ.attributes('id'))
        await champ.setValue('Rowing lourd')

        expect(wrapper.emitted('update:modelValue')).toEqual([['Rowing lourd']])
    })

    it('compte les caractères quand une longueur est posée', async () => {
        const wrapper = monter({ modelValue: 'abc', maxlength: 10 })

        expect(wrapper.text()).toContain('3 / 10')
        expect(wrapper.get('textarea').attributes('maxlength')).toBe('10')
    })

    it('annonce l’erreur et la relie au champ', () => {
        const wrapper = monter({ error: 'Trop long.' })
        const champ = wrapper.get('textarea')

        expect(champ.attributes('aria-invalid')).toBe('true')
        expect(wrapper.get('[role="alert"]').attributes('id')).toBe(champ.attributes('aria-describedby'))
    })

    it('cache l’étiquette aux yeux mais pas aux lecteurs d’écran', () => {
        expect(monter({ hideLabel: true }).get('label').classes()).toContain('sr-only')
    })

    it('suit la hauteur du texte', async () => {
        const wrapper = monter({ modelValue: 'a' })
        const champ = wrapper.get('textarea').element
        Object.defineProperty(champ, 'scrollHeight', { configurable: true, value: 120 })

        await wrapper.setProps({ modelValue: 'a\nb\nc\nd' })
        await wrapper.vm.$nextTick()

        expect(champ.style.height).toBe('120px')
    })
})

/**
 * Le compteur « n / max » portait `aria-live="polite"` : un lecteur d'écran
 * mettait en file « 1 / 1000 », « 2 / 1000 »… à chaque caractère, par-dessus
 * l'écho de la saisie, alors que le champ le désigne déjà par
 * `aria-describedby` et qu'il est lu à la prise du focus (#2121). Seule
 * l'approche de la limite s'annonce, une fois par palier.
 */
describe('GlassTextarea — ce que le compteur annonce', () => {
    const LIMITE = 1000

    /** Le texte de chaque région annoncée, dans l'ordre du gabarit. */
    const regionsAnnoncees = (wrapper) => wrapper.findAll('[aria-live]').map((region) => region.text())

    /** Saisit le texte caractère par caractère, comme le ferait le clavier, et relève les régions après chacun. */
    const saisir = async (wrapper, depart, texte) => {
        const releves = []
        let valeur = depart

        for (const caractere of texte) {
            valeur += caractere
            await wrapper.setProps({ modelValue: valeur })
            releves.push(regionsAnnoncees(wrapper))
        }

        return releves
    }

    it('ne fait rien annoncer pendant la saisie, loin de la limite', async () => {
        const wrapper = monter({ maxlength: LIMITE })
        const avant = regionsAnnoncees(wrapper)
        const releves = await saisir(wrapper, '', 'abcde')

        expect(wrapper.get('[id$="-compteur"]').text()).toBe(`5 / ${LIMITE}`)
        expect(releves).toEqual(Array(5).fill(avant))
        expect(avant.every((texte) => texte === '')).toBe(true)
    })

    it('laisse le compteur lu à la prise du focus, sans en faire une région annoncée', () => {
        const wrapper = monter({ modelValue: 'abc', maxlength: LIMITE })
        const compteur = wrapper.get('[id$="-compteur"]')

        expect(wrapper.get('textarea').attributes('aria-describedby')).toContain(compteur.attributes('id'))
        expect(compteur.text()).toBe(`3 / ${LIMITE}`)
        expect(compteur.attributes('aria-live')).toBeUndefined()
    })

    it('prévient en approchant de la limite, une fois par palier', async () => {
        const wrapper = monter({ modelValue: 'x'.repeat(LIMITE - 52), maxlength: LIMITE })

        expect(regionsAnnoncees(wrapper)).toEqual([''])

        const releves = await saisir(wrapper, 'x'.repeat(LIMITE - 52), 'y'.repeat(52))
        const annonces = releves.map(([texte]) => texte).filter((texte, rang, tous) => texte !== tous[rang - 1])

        expect(annonces).toEqual([
            '',
            'Plus que 50 caractères.',
            'Plus que 25 caractères.',
            'Plus que 10 caractères.',
            'Limite atteinte.',
        ])
    })

    it('se tait de nouveau quand le texte s’éloigne de la limite', async () => {
        const wrapper = monter({ modelValue: 'x'.repeat(LIMITE - 60), maxlength: LIMITE })

        // Un collage franchit d'un coup les paliers de 50, 25 et 10 : une seule
        // annonce, qui dit ce qui reste vraiment.
        await wrapper.setProps({ modelValue: 'x'.repeat(LIMITE - 5) })
        expect(regionsAnnoncees(wrapper)).toEqual(['Plus que 5 caractères.'])

        await wrapper.setProps({ modelValue: 'x'.repeat(LIMITE - 300) })
        expect(regionsAnnoncees(wrapper)).toEqual([''])
    })

    it('n’annonce rien sans longueur maximale', async () => {
        const wrapper = monter()

        await saisir(wrapper, '', 'abc')

        expect(regionsAnnoncees(wrapper)).toEqual([])
    })
})

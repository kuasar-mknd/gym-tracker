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

    /*
     * Un texte prérempli près de la limite (une note de séance, la
     * description d'un modèle) ne parle pas à l'ouverture de la page : la
     * région n'annonce qu'un palier franchi pendant la saisie. Les autres cas
     * montent le champ loin de la limite, où le palier est nul et où une
     * annonce dès le montage ne se distinguerait pas du silence.
     */
    it('se tait au montage, même près de la limite', () => {
        expect(regionsAnnoncees(monter({ modelValue: 'x'.repeat(LIMITE - 5), maxlength: LIMITE }))).toEqual([''])
        expect(regionsAnnoncees(monter({ modelValue: 'x'.repeat(LIMITE), maxlength: LIMITE }))).toEqual([''])
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

    /** Efface le texte caractère par caractère, comme la touche retour, et relève les régions après chacun. */
    const effacer = async (wrapper, depart, combien) => {
        const releves = []

        for (let longueur = depart.length - 1; longueur >= depart.length - combien; longueur -= 1) {
            await wrapper.setProps({ modelValue: depart.slice(0, longueur) })
            releves.push(regionsAnnoncees(wrapper)[0])
        }

        return releves
    }

    /*
     * La région parlait à chaque changement de palier, dans les deux sens : en
     * effaçant depuis la limite, elle disait « Plus que 1 caractère. », puis
     * « Plus que 11 caractères. », puis « Plus que 26 caractères. », des nombres
     * qui ne sont pas des paliers, alors que la règle et le commentaire du
     * composant la disent muette quand le texte s'éloigne de la limite.
     */
    it('ne dit rien en effaçant depuis la limite', async () => {
        const plein = 'x'.repeat(LIMITE - 12)
        const wrapper = monter({ modelValue: plein, maxlength: LIMITE })
        const approche = await saisir(wrapper, plein, 'y'.repeat(12))

        expect(approche.at(-1)).toEqual(['Limite atteinte.'])
        expect(await effacer(wrapper, plein + 'y'.repeat(12), 60)).toEqual(Array(60).fill(''))
    })

    it('ne dit rien en effaçant un texte prérempli à la limite', async () => {
        const plein = 'x'.repeat(LIMITE)
        const wrapper = monter({ modelValue: plein, maxlength: LIMITE })

        expect(await effacer(wrapper, plein, 60)).toEqual(Array(60).fill(''))
    })

    /*
     * Une saisie qui hésite autour d'un seuil (une lettre, la touche retour, une
     * lettre…) ne fait pas répéter l'annonce : le palier le plus serré déjà
     * annoncé se retient tant que le texte reste à moins de 50 caractères de la
     * limite.
     */
    it('n’annonce un palier qu’une fois quand la saisie hésite autour de son seuil', async () => {
        const depart = 'x'.repeat(LIMITE - 11)
        const wrapper = monter({ modelValue: depart, maxlength: LIMITE })
        const releves = []

        for (const longueur of [LIMITE - 10, LIMITE - 11, LIMITE - 10, LIMITE - 11, LIMITE - 10]) {
            await wrapper.setProps({ modelValue: 'x'.repeat(longueur) })
            releves.push(regionsAnnoncees(wrapper)[0])
        }

        expect(releves.filter((texte) => texte !== '')).toEqual(['Plus que 10 caractères.'])
    })

    it('annonce de nouveau l’approche une fois le texte revenu loin de la limite', async () => {
        const wrapper = monter({ modelValue: 'x'.repeat(LIMITE - 60), maxlength: LIMITE })

        await wrapper.setProps({ modelValue: 'x'.repeat(LIMITE - 5) })
        expect(regionsAnnoncees(wrapper)).toEqual(['Plus que 5 caractères.'])

        await wrapper.setProps({ modelValue: 'x'.repeat(LIMITE - 300) })
        await wrapper.setProps({ modelValue: 'x'.repeat(LIMITE - 50) })
        expect(regionsAnnoncees(wrapper)).toEqual(['Plus que 50 caractères.'])
    })

    it('n’annonce rien sans longueur maximale', async () => {
        const wrapper = monter()

        await saisir(wrapper, '', 'abc')

        expect(regionsAnnoncees(wrapper)).toEqual([])
    })
})

import { describe, it, expect } from 'vitest'
import { config, mount } from '@vue/test-utils'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'

const mountState = (props = {}, slots = {}) =>
    mount(GlassEmptyState, {
        props: { title: 'Aucune séance', ...props },
        slots,
        global: { directives: { press: {} } },
    })

/** The blurred disc sitting behind the icon; its colour is the only thing on it. */
const glowOf = (wrapper) => wrapper.get('.blur-3xl')

const iconSpans = (wrapper) => wrapper.findAll('.h-20 span')

describe('GlassEmptyState', () => {
    it('shows the description only when the caller supplies one', () => {
        const withText = mountState({ description: 'Commence ton aventure' })
        const without = mountState()

        expect(withText.text()).toContain('Commence ton aventure')
        expect(withText.text()).toContain('Aucune séance')
        expect(without.findAll('p')).toHaveLength(0)
    })

    it('renders an emoji as text, not through the icon font', () => {
        const wrapper = mountState({ icon: '💪' })

        expect(iconSpans(wrapper)).toHaveLength(1)
        expect(iconSpans(wrapper)[0].text()).toBe('💪')
        expect(iconSpans(wrapper)[0].classes()).not.toContain('material-symbols-outlined')
    })

    it('renders a Material Symbols name as a ligature the font can resolve', () => {
        const wrapper = mountState({ icon: 'search_off' })

        expect(iconSpans(wrapper)).toHaveLength(1)
        expect(iconSpans(wrapper)[0].classes()).toContain('material-symbols-outlined')
        expect(iconSpans(wrapper)[0].attributes('aria-hidden')).toBe('true')
    })

    it('leaves the icon slot free when no icon prop is given', () => {
        const wrapper = mountState({}, { icon: '<svg data-testid="custom-icon" />' })

        expect(wrapper.find('[data-testid="custom-icon"]').exists()).toBe(true)
    })

    /*
     * The colour prop feeds two places: a validator that decides what is legal,
     * and a palette map that turns it into a class. They are separate
     * declarations, so a colour can be accepted and then resolve to nothing —
     * which is not a visible failure, only a glow that quietly disappears.
     */
    const candidates = ['orange', 'violet', 'pink', 'cyan', 'green', 'red', 'blue', 'slate']
    const accepted = candidates.filter((color) => GlassEmptyState.props.color.validator(color))

    it('gives every colour it accepts a glow to go with it', () => {
        expect(accepted.length).toBeGreaterThan(0)

        for (const color of accepted) {
            const classes = glowOf(mountState({ color })).classes()

            expect(
                classes.some((klass) => klass.startsWith('bg-')),
                `no glow class for "${color}"`,
            ).toBe(true)
        }
    })

    /*
     * The other direction of the same invariant. `accepted` is derived from the
     * validator, so asserting the validator rejects everything else is true by
     * construction and proves nothing; what can actually drift is a colour
     * gaining a glow in the palette map while the validator still turns it away
     * — a colour that works, warns in the console, and is refused on review.
     */
    it('has no glow waiting for a colour it refuses', () => {
        const rejected = candidates.filter((color) => !accepted.includes(color))

        expect(rejected.length).toBeGreaterThan(0)

        /*
         * Ce test monte EXPRES des couleurs que le validateur refuse : c'est
         * tout son objet. L'avertissement de Vue est donc attendu, et il ne
         * doit pas partir dans la console — un journal ecrit apres la fin du
         * fichier fait sortir Vitest en erreur (voir vitest.setup.js).
         */
        const precedent = config.global.config.warnHandler
        config.global.config.warnHandler = () => {}

        try {
            for (const color of rejected) {
                const classes = glowOf(mountState({ color })).classes()

                expect(
                    classes.some((klass) => klass.startsWith('bg-')),
                    `"${color}" is refused by the validator but has a glow`,
                ).toBe(false)
            }
        } finally {
            config.global.config.warnHandler = precedent
        }
    })

    it('offers the action and reports the click back to the page', async () => {
        const wrapper = mountState({ actionLabel: 'Commencer maintenant' })

        const button = wrapper.get('[data-testid="empty-state-action"]')

        expect(button.text()).toContain('Commencer maintenant')

        await button.trigger('click')

        expect(wrapper.emitted('action')).toHaveLength(1)
    })

    it('lets the page name the action button so its own tests can find it', () => {
        const wrapper = mountState({ actionLabel: 'Créer', actionId: 'create-exercise-button' })

        expect(wrapper.find('[data-testid="create-exercise-button"]').exists()).toBe(true)
    })

    it('shows no action at all when the page offers none', () => {
        const wrapper = mountState({ description: 'Rien à faire ici' })

        expect(wrapper.find('button').exists()).toBe(false)
    })

    it('lets the page replace the button with its own control', () => {
        const wrapper = mountState({}, { action: '<button data-testid="clear-search">Effacer</button>' })

        expect(wrapper.find('[data-testid="clear-search"]').exists()).toBe(true)
        expect(wrapper.find('[data-testid="empty-state-action"]').exists()).toBe(false)
    })
})

/**
 * Les deux défauts de #1386, tous deux silencieux : rien n'échouait, l'icône
 * sortait simplement fausse et la couleur simplement absente.
 */
describe('GlassEmptyState — ce que « court » voulait dire', () => {
    /**
     * `icon.length <= 2` compte les unités UTF-16, pas les caractères perçus.
     * 💪 en vaut 2 et passait ; tous ceux qui portent un sélecteur de variante,
     * un modificateur de genre ou une paire régionale en valent plus, et
     * partaient dans la branche icône pour se rendre comme un nom de ligature
     * inexistant — texte brut à l'écran, ou rien.
     *
     * La longueur était de toute façon la mauvaise question : ce qui distingue
     * les deux, c'est qu'un nom de ligature s'écrit en ASCII minuscule.
     */
    it.each([
        ['🏋️', 'sélecteur de variante, 3 unités'],
        ['🏋️‍♂️', 'modificateur de genre, 6 unités'],
        ['🇫🇷', 'paire régionale, 4 unités'],
        ['👨‍👩‍👧‍👦', 'famille jointe par ZWJ, 11 unités'],
    ])('rend %s en texte (%s)', (emoji) => {
        const wrapper = mountState({ icon: emoji })

        expect(iconSpans(wrapper)).toHaveLength(1)
        expect(iconSpans(wrapper)[0].text()).toBe(emoji)
        expect(iconSpans(wrapper)[0].classes()).not.toContain('material-symbols-outlined')
    })

    /**
     * Le scanner de Tailwind ne lit que des chaînes littérales complètes : une
     * classe assemblée à l'exécution — `text-${...}` — n'apparaît jamais dans
     * le CSS généré. La classe est donc lue ici en toutes lettres, ce qui est
     * exactement ce que le scanner doit pouvoir trouver dans la source.
     */
    it.each([
        ['orange', 'text-accent-primary-deep'],
        ['violet', 'text-accent-tertiary'],
        ['pink', 'text-accent-secondary-deep'],
        ['cyan', 'text-accent-info-deep'],
        ['green', 'text-accent-state-deep'],
    ])('peint l’icône de %s avec %s', (color, expected) => {
        const wrapper = mountState({ icon: 'search_off', color })

        expect(iconSpans(wrapper)[0].classes()).toContain(expected)
    })

    /**
     * La contrepartie du test ci-dessus, et le seul des deux qui morde.
     *
     * Un littéral de gabarit rend exactement la même classe dans le DOM : les
     * assertions ci-dessus passaient donc avec le code fautif. Ce qui manquait
     * n'était pas la classe sur l'élément, c'était la règle dans la feuille —
     * le scanner de Tailwind ne lit que des chaînes littérales complètes. Il
     * faut regarder le fichier, pas le rendu.
     *
     * Mesuré dans le CSS généré, avant correctif : `text-accent-primary-deep`,
     * `text-accent-tertiary`, `text-accent-secondary-deep` et `text-accent-info-deep` étaient bien
     * présents — non pas grâce à ce composant, mais parce que d'autres les
     * écrivent en toutes lettres. `text-accent-state-deep`, que personne d'autre
     * n'emploie, était absent : `color="green"` peignait donc l'icône avec une
     * classe sans règle. Quatre couleurs sur cinq marchaient par emprunt, ce
     * qui est la pire façon de marcher — laquelle dépendait de fichiers sans
     * rapport.
     *
     * Note pour plus tard : ce fichier de test nomme lui-même les cinq classes,
     * et Tailwind scanne `tests/`. Elles resteront donc dans la feuille même si
     * le composant régresse. C'est l'assertion sur la source, ci-dessous, qui
     * attrape la régression — pas leur présence dans le CSS.
     */
    it('écrit les classes de couleur en toutes lettres dans la source', async () => {
        const source = await import('@/Components/UI/GlassEmptyState.vue?raw').then((m) => m.default)

        for (const expected of [
            'text-accent-primary-deep',
            'text-accent-tertiary',
            'text-accent-secondary-deep',
            'text-accent-info-deep',
            'text-accent-state-deep',
        ]) {
            expect(source).toContain(expected)
        }

        // La liaison, pas la chaîne : le commentaire du composant cite
        // l'anti-patron pour expliquer pourquoi il a été retiré.
        expect(source).not.toContain(':class="`text-')
    })
})

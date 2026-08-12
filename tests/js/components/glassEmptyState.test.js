import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
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

        for (const color of rejected) {
            const classes = glowOf(mountState({ color })).classes()

            expect(
                classes.some((klass) => klass.startsWith('bg-')),
                `"${color}" is refused by the validator but has a glow`,
            ).toBe(false)
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

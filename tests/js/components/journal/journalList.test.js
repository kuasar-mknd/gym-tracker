import { describe, it, expect, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'

import JournalList from '@/Components/Journal/JournalList.vue'

/**
 * The dates come out of the server as calendar days. Read as instants they
 * render the day before anywhere behind UTC, so the timezone is set west on
 * purpose — from UTC the broken and the correct forms agree.
 */
const originalTz = process.env.TZ

afterEach(() => {
    // TZ is unset in this project, so `process.env.TZ = originalTz` would write
    // the string "undefined" — an unparseable zone Node silently reads as UTC.
    // The "restore" then leaves the process an hour or more away from where it
    // found it, for every test that runs after.
    if (originalTz === undefined) {
        delete process.env.TZ
    } else {
        process.env.TZ = originalTz
    }
})

const moods = [
    { value: 1, label: '😞 Terrible' },
    { value: 3, label: '😐 Correct' },
    { value: 5, label: '🤩 Excellent' },
]

const entry = (overrides = {}) => ({
    id: 1,
    date: '2026-07-31',
    content: 'Séance lourde, dos en feu.',
    mood_score: null,
    sleep_quality: null,
    stress_level: null,
    energy_level: null,
    motivation_level: null,
    nutrition_score: null,
    training_intensity: null,
    ...overrides,
})

const mountList = (journalsByMonth, props = {}) =>
    mount(JournalList, {
        props: { journalsByMonth, moods, ...props },
        global: { directives: { press: {} } },
    })

const card = (wrapper, index = 0) => wrapper.findAllComponents({ name: 'GlassCard' })[index]

describe('JournalList — the date column', () => {
    it('shows the day the entry was written, west of UTC', () => {
        process.env.TZ = 'America/New_York'

        const wrapper = mountList({ 'juillet 2026': [entry({ date: '2026-07-31' })] })

        expect(card(wrapper).text()).toContain('31')
        expect(card(wrapper).text()).toContain('ven.')
    })

    it('takes the day out of a full timestamp too', () => {
        process.env.TZ = 'America/New_York'

        const wrapper = mountList({ 'juillet 2026': [entry({ date: '2026-07-31T00:00:00.000000Z' })] })

        expect(card(wrapper).text()).toContain('31')
    })
})

describe('JournalList — grouping', () => {
    it('keeps each month with its own entries, in the order the page grouped them', () => {
        const wrapper = mountList({
            'août 2026': [entry({ id: 2, date: '2026-08-03', content: 'Repos' })],
            'juillet 2026': [entry({ id: 1, date: '2026-07-31', content: 'Squat' })],
        })

        const months = wrapper.findAll('h3').map((h) => h.text())

        expect(months).toEqual(['août 2026', 'juillet 2026'])
        expect(card(wrapper, 0).text()).toContain('Repos')
        expect(card(wrapper, 1).text()).toContain('Squat')
    })

    it('renders one card per entry of a month', () => {
        const wrapper = mountList({
            'juillet 2026': [entry({ id: 1 }), entry({ id: 2 }), entry({ id: 3 })],
        })

        expect(wrapper.findAllComponents({ name: 'GlassCard' })).toHaveLength(3)
    })
})

describe('JournalList — what each entry shows', () => {
    it('shows the mood as its emoji, not as its whole label', () => {
        const wrapper = mountList({ 'juillet 2026': [entry({ mood_score: 5 })] })

        expect(card(wrapper).text()).toContain('🤩')
        expect(card(wrapper).text()).not.toContain('Excellent')
    })

    it('leaves out the mood block entirely when the entry recorded none', () => {
        const wrapper = mountList({ 'juillet 2026': [entry({ mood_score: null })] })

        // Not merely "renders empty": an empty mood slot still takes its width
        // in the flex row and reads as an unlabelled element.
        expect(wrapper.find('[title="Humeur"]').exists()).toBe(false)
    })

    /** The page owns the moods list; a score it no longer contains must not throw. */
    it('survives a mood score the page has no label for', () => {
        const wrapper = mountList({ 'juillet 2026': [entry({ mood_score: 4 })] })

        expect(wrapper.find('[title="Humeur"]').text()).toBe('')
    })

    /**
     * Sleep and diet are scored out of five, the other four out of ten. Reading
     * "Stress: 7/5" tells the user nothing true about how bad the week was.
     */
    it('scores each metric against its own scale', () => {
        const wrapper = mountList({
            'juillet 2026': [
                entry({
                    sleep_quality: 4,
                    nutrition_score: 3,
                    stress_level: 7,
                    energy_level: 8,
                    motivation_level: 9,
                    training_intensity: 6,
                }),
            ],
        })

        const text = card(wrapper).text()

        expect(text).toContain('💤 4/5')
        expect(text).toContain('🥗 Diète: 3/5')
        expect(text).toContain('⚡ Stress: 7/10')
        expect(text).toContain('🔋 Énergie: 8/10')
        expect(text).toContain('🔥 Motivation: 9/10')
        expect(text).toContain('🏋️ Intensité: 6/10')
    })

    it('leaves out the badges the entry did not record', () => {
        const wrapper = mountList({ 'juillet 2026': [entry({ stress_level: 7 })] })

        const text = card(wrapper).text()

        expect(text).toContain('Stress')
        expect(text).not.toContain('Énergie')
        expect(text).not.toContain('Diète')
        expect(text).not.toContain('Motivation')
    })

    it('shows the entry’s own text', () => {
        const wrapper = mountList({ 'juillet 2026': [entry({ content: 'Séance lourde, dos en feu.' })] })

        expect(card(wrapper).text()).toContain('Séance lourde, dos en feu.')
    })
})

describe('JournalList — the two actions', () => {
    it('asks to edit the entry it was asked about, not another one', async () => {
        const second = entry({ id: 42, content: 'Deuxième' })
        const wrapper = mountList({ 'juillet 2026': [entry({ id: 41 }), second] })

        await wrapper.findAll('[aria-label="Modifier l\'entrée"]')[1].trigger('click')

        expect(wrapper.emitted('edit')).toEqual([[second]])
    })

    /** Deletion is keyed by id; handing the whole record over would delete nothing. */
    it('asks to delete by id', async () => {
        const wrapper = mountList({ 'juillet 2026': [entry({ id: 41 }), entry({ id: 42 })] })

        await wrapper.findAll('[aria-label="Supprimer l\'entrée"]')[1].trigger('click')

        expect(wrapper.emitted('delete')).toEqual([[42]])
    })
})

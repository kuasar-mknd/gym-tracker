import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { reactive } from 'vue'

import JournalForm from '@/Components/Journal/JournalForm.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'

/** The mood scale the journal page hands down, best first. */
const moods = [
    { value: 5, label: '🤩 Excellent' },
    { value: 4, label: '🙂 Bon' },
    { value: 3, label: '😐 Moyen' },
    { value: 2, label: '🙁 Mauvais' },
    { value: 1, label: '😫 Terrible' },
]

const makeForm = (overrides = {}) =>
    reactive({
        date: '2026-03-10',
        mood_score: null,
        sleep_quality: null,
        stress_level: null,
        energy_level: null,
        motivation_level: null,
        nutrition_score: null,
        training_intensity: null,
        content: '',
        processing: false,
        errors: {},
        ...overrides,
    })

const mountForm = ({ form = makeForm(), editingJournal = null } = {}) => ({
    form,
    wrapper: mount(JournalForm, {
        props: { form, moods, editingJournal },
        global: { directives: { press: {} } },
    }),
})

/** The mood radios, best first, as the scale was given. */
const moodRadios = (wrapper) => wrapper.findAll('[role="radio"]')

/** A field, found through the label the user reads rather than by position. */
const fieldLabelled = (wrapper, label) =>
    wrapper.findAllComponents(GlassInput).find((field) => field.props('label') === label)

const boxLabelled = (wrapper, label) => fieldLabelled(wrapper, label).find('input')

/*
 * The page around this form was tested through its props; the form itself was
 * never mounted, so none of its ten fields, its mood scale or its two exits had
 * ever been exercised. A v-model on the wrong key here silently files last
 * night's sleep as this morning's stress.
 */
describe('the journal form', () => {
    it('says whether it is opening a new entry or an old one', () => {
        expect(mountForm().wrapper.find('h3').text()).toBe('Nouvelle entrée')
        expect(
            mountForm({ editingJournal: { id: 4 } })
                .wrapper.find('h3')
                .text(),
        ).toBe("Modifier l'entrée")
    })

    it('offers two ways out, and both of them close', async () => {
        const { wrapper } = mountForm()

        await wrapper.find('[aria-label="Fermer le formulaire"]').trigger('click')
        await wrapper
            .findAll('button')
            .find((button) => button.text().trim() === 'Annuler')
            .trigger('click')

        // Two exits, two events: a cancel button wired to submit would save the
        // entry the user was backing out of.
        expect(wrapper.emitted('close')).toHaveLength(2)
        expect(wrapper.emitted('submit')).toBeUndefined()
    })

    it('hands the saving back to the page rather than posting itself', async () => {
        const { wrapper } = mountForm()

        await wrapper.find('form').trigger('submit')

        expect(wrapper.emitted('submit')).toHaveLength(1)
        expect(wrapper.emitted('close')).toBeUndefined()
    })
})

describe('the mood scale', () => {
    it('offers one radio per mood, named in full', () => {
        const { wrapper } = mountForm()

        // The button shows only the emoji, which is nothing to a screen reader;
        // the whole label has to be the accessible name.
        expect(moodRadios(wrapper).map((radio) => radio.attributes('aria-label'))).toEqual([
            '🤩 Excellent',
            '🙂 Bon',
            '😐 Moyen',
            '🙁 Mauvais',
            '😫 Terrible',
        ])
        expect(moodRadios(wrapper).map((radio) => radio.text())).toEqual(['🤩', '🙂', '😐', '🙁', '😫'])
    })

    it('records the mood that was picked, by value and not by position', async () => {
        const { wrapper, form } = mountForm()

        // Fourth in the list, worth 2 — the two numbers differ on purpose, so a
        // handler that stored the index would show up.
        await moodRadios(wrapper)[3].trigger('click')

        expect(form.mood_score).toBe(2)
    })

    it('checks exactly the mood that is stored', async () => {
        const { wrapper } = mountForm()

        expect(moodRadios(wrapper).map((radio) => radio.attributes('aria-checked'))).toEqual([
            'false',
            'false',
            'false',
            'false',
            'false',
        ])

        await moodRadios(wrapper)[3].trigger('click')

        expect(moodRadios(wrapper).map((radio) => radio.attributes('aria-checked'))).toEqual([
            'false',
            'false',
            'false',
            'true',
            'false',
        ])
    })

    it('shows the error the server raised against the mood', () => {
        const { wrapper } = mountForm({ form: makeForm({ errors: { mood_score: 'Humeur requise.' } }) })

        expect(wrapper.text()).toContain('Humeur requise.')
    })
})

describe('the daily figures', () => {
    it('writes each box into the field it is labelled with', async () => {
        const { wrapper, form } = mountForm()

        // Seven values that differ from each other, so a v-model bound to a
        // neighbouring key cannot pass.
        await boxLabelled(wrapper, 'Date').setValue('2026-03-11')
        await boxLabelled(wrapper, 'Sommeil (1-5)').setValue('4')
        await boxLabelled(wrapper, 'Stress (1-10)').setValue('7')
        await boxLabelled(wrapper, 'Énergie (1-10)').setValue('9')
        await boxLabelled(wrapper, 'Motivation (1-10)').setValue('6')
        await boxLabelled(wrapper, 'Diète (1-5)').setValue('3')
        await boxLabelled(wrapper, 'Intensité (1-10)').setValue('8')

        expect(form.date).toBe('2026-03-11')
        expect(form.sleep_quality).toBe('4')
        expect(form.stress_level).toBe('7')
        expect(form.energy_level).toBe('9')
        expect(form.motivation_level).toBe('6')
        expect(form.nutrition_score).toBe('3')
        expect(form.training_intensity).toBe('8')
    })

    it('puts each server error under the field it belongs to', () => {
        const { wrapper } = mountForm({
            form: makeForm({
                errors: {
                    sleep_quality: 'Sommeil entre 1 et 5.',
                    stress_level: 'Stress entre 1 et 10.',
                },
            }),
        })

        // Field by field: both messages appear on the page either way, and two
        // errors shown under each other's box would read as correct.
        expect(fieldLabelled(wrapper, 'Sommeil (1-5)').text()).toContain('Sommeil entre 1 et 5.')
        expect(fieldLabelled(wrapper, 'Stress (1-10)').text()).toContain('Stress entre 1 et 10.')
        expect(fieldLabelled(wrapper, 'Sommeil (1-5)').text()).not.toContain('Stress entre')
        // And the untouched fields stay clean.
        expect(fieldLabelled(wrapper, 'Énergie (1-10)').text()).not.toContain('entre')
    })
})

describe('the notes box', () => {
    it('counts what has been written, starting from nothing', async () => {
        const { wrapper } = mountForm()

        expect(wrapper.find('#journal-content-counter').text()).toBe('0 / 1000')

        await wrapper.find('#journal-content').setValue('Bonne séance.')

        expect(wrapper.find('#journal-content-counter').text()).toBe('13 / 1000')
    })

    it('keeps the counter tied to the box that feeds it', () => {
        const { wrapper } = mountForm()

        // Without the reference the count is read out as a stray number, or not
        // at all, when the textarea takes focus.
        expect(wrapper.find('#journal-content').attributes('aria-describedby')).toBe('journal-content-counter')
        expect(wrapper.find('#journal-content').attributes('maxlength')).toBe('1000')
    })

    it('warns only once the limit is actually passed', () => {
        const atLimit = mountForm({ form: makeForm({ content: 'x'.repeat(1000) }) })
        const over = mountForm({ form: makeForm({ content: 'x'.repeat(1001) }) })

        // 1000 is the limit, not one past it: a `>=` here would paint a full
        // but perfectly valid note red.
        expect(atLimit.wrapper.find('#journal-content-counter').classes()).toContain('text-text-muted/50')
        expect(atLimit.wrapper.find('#journal-content-counter').classes()).not.toContain('text-accent-danger')
        expect(over.wrapper.find('#journal-content-counter').classes()).toContain('text-accent-danger')
    })

    it('reads an entry with no note at all as zero, not as NaN', () => {
        const { wrapper } = mountForm({ form: makeForm({ content: null }) })

        expect(wrapper.find('#journal-content-counter').text()).toBe('0 / 1000')
    })

    it('shows the error the server raised against the note', () => {
        const { wrapper } = mountForm({ form: makeForm({ errors: { content: 'Note trop longue.' } }) })

        expect(wrapper.text()).toContain('Note trop longue.')
    })
})

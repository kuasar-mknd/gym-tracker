import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'

/**
 * The library page filters, groups and optimistically deletes entirely on the
 * client. Everything asserted here is what the user sees as a consequence of
 * that: which exercises survive a filter, which heading they end up under, and
 * whether a refused deletion puts the row back where it was.
 */
const hoisted = vi.hoisted(() => ({
    routerDelete: vi.fn(),
    haptics: vi.fn(),
}))

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    router: {
        delete: (...args) => hoisted.routerDelete(...args),
        reload: vi.fn(),
        visit: vi.fn(),
    },
    useForm: (fields) => ({
        ...fields,
        errors: {},
        processing: false,
        post: vi.fn(),
        put: vi.fn(),
        reset: vi.fn(),
    }),
}))

vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: (...args) => hoisted.haptics(...args) }))

import ExercisesIndex from '@/Pages/Exercises/Index.vue'

const PECS = { id: 1, name: 'Développé couché', type: 'strength', category: 'Pectoraux' }
const BACK = { id: 2, name: 'Tractions', type: 'strength', category: 'Dos' }
const PECS_2 = { id: 3, name: 'Écarté couché', type: 'strength', category: 'Pectoraux' }
const UNCLASSIFIED = { id: 4, name: 'Gainage', type: 'timed', category: null }

const passesSlot = { template: '<div><slot /></div>' }

const ExerciseCardStub = {
    name: 'ExerciseCard',
    props: ['exercise', 'isEditing', 'editForm', 'category', 'types', 'categories', 'typeLabel'],
    template: '<div class="exercise-card">{{ exercise.name }}</div>',
}

beforeAll(() => {
    globalThis.route = (name, params) => `/${name}/${Object.values(params ?? {}).join('/')}`
})

beforeEach(() => {
    hoisted.routerDelete.mockReset()
    hoisted.haptics.mockReset()
    window.history.replaceState({}, '', '/exercises')
})

afterEach(() => {
    vi.restoreAllMocks()
})

const mountPage = (exercises, options = {}) =>
    mount(ExercisesIndex, {
        props: { exercises },
        attachTo: document.body,
        global: {
            directives: { press: {} },
            mocks: { $page: { props: { errors: {} } } },
            stubs: {
                AuthenticatedLayout: passesSlot,
                ExerciseCategoryChart: true,
                ExerciseCard: ExerciseCardStub,
            },
        },
        ...options,
    })

/** The exercises actually rendered, in order, with the heading they sit under. */
const renderedCards = (wrapper) =>
    wrapper.findAllComponents({ name: 'ExerciseCard' }).map((card) => ({
        name: card.props('exercise').name,
        category: card.props('category'),
    }))

describe('Exercises/Index — filtrage et regroupement', () => {
    it('regroupe les exercices par catégorie', () => {
        const wrapper = mountPage([PECS, BACK, PECS_2])

        expect(renderedCards(wrapper)).toEqual([
            { name: 'Développé couché', category: 'Pectoraux' },
            { name: 'Écarté couché', category: 'Pectoraux' },
            { name: 'Tractions', category: 'Dos' },
        ])

        wrapper.unmount()
    })

    it('range sous « Autres » un exercice sans catégorie', () => {
        const wrapper = mountPage([UNCLASSIFIED])

        expect(renderedCards(wrapper)).toEqual([{ name: 'Gainage', category: 'Autres' }])

        wrapper.unmount()
    })

    it('affiche le nombre d’exercices de chaque groupe', () => {
        const wrapper = mountPage([PECS, BACK, PECS_2])

        // Read off the count element itself rather than the group's whole text:
        // any digit anywhere under the heading would satisfy a `toContain`.
        const groups = wrapper.findAll('.space-y-8 > div').map((group) => ({
            category: group.get('h3').text(),
            count: group.get('.mb-3 span').text(),
        }))

        expect(groups).toEqual([
            { category: 'Pectoraux', count: '2' },
            { category: 'Dos', count: '1' },
        ])

        wrapper.unmount()
    })

    it('filtre sur le nom sans tenir compte de la casse', async () => {
        const wrapper = mountPage([PECS, BACK, PECS_2])

        await wrapper.find('#search-exercises-input').setValue('TRACTIONS')

        expect(renderedCards(wrapper)).toEqual([{ name: 'Tractions', category: 'Dos' }])

        wrapper.unmount()
    })

    it('filtre sur un fragment de nom, pas seulement sur le début', async () => {
        const wrapper = mountPage([PECS, BACK, PECS_2])

        await wrapper.find('#search-exercises-input').setValue('couché')

        expect(renderedCards(wrapper).map((card) => card.name)).toEqual(['Développé couché', 'Écarté couché'])

        wrapper.unmount()
    })

    it('ne garde que la catégorie choisie', async () => {
        const wrapper = mountPage([PECS, BACK, PECS_2])

        await wrapper.find('[dusk="category-pill-Dos"]').trigger('click')

        expect(renderedCards(wrapper)).toEqual([{ name: 'Tractions', category: 'Dos' }])

        wrapper.unmount()
    })

    it('croise la recherche et la catégorie', async () => {
        const wrapper = mountPage([PECS, BACK, PECS_2])

        await wrapper.find('[dusk="category-pill-Pectoraux"]').trigger('click')
        await wrapper.find('#search-exercises-input').setValue('Écarté')

        expect(renderedCards(wrapper).map((card) => card.name)).toEqual(['Écarté couché'])

        wrapper.unmount()
    })

    it('revient à tous les exercices en cliquant sur « Tous »', async () => {
        const wrapper = mountPage([PECS, BACK, PECS_2])

        await wrapper.find('[dusk="category-pill-Dos"]').trigger('click')
        await wrapper.find('[dusk="category-pill-all"]').trigger('click')

        expect(renderedCards(wrapper)).toHaveLength(3)

        wrapper.unmount()
    })

    it('nomme le terme cherché quand il ne donne rien', async () => {
        const wrapper = mountPage([PECS, BACK])

        await wrapper.find('#search-exercises-input').setValue('squat bulgare')

        expect(wrapper.text()).toContain('Aucun résultat pour squat bulgare')
        expect(renderedCards(wrapper)).toHaveLength(0)

        wrapper.unmount()
    })

    it('efface la recherche depuis l’état « aucun résultat »', async () => {
        const wrapper = mountPage([PECS, BACK])

        await wrapper.find('#search-exercises-input').setValue('squat bulgare')

        const clear = wrapper.findAll('button').find((button) => button.text().includes('Effacer la recherche'))
        await clear.trigger('click')

        expect(renderedCards(wrapper)).toHaveLength(2)

        wrapper.unmount()
    })

    it('propose de créer le premier exercice quand la bibliothèque est vide', () => {
        const wrapper = mountPage([])

        expect(renderedCards(wrapper)).toHaveLength(0)
        expect(wrapper.text()).toContain("Aucun exercice pour l'instant")
        expect(wrapper.text()).toContain('Créer le premier exercice')

        wrapper.unmount()
    })
})

describe('Exercises/Index — la catégorie vit dans l’URL', () => {
    it('ouvre la bibliothèque déjà filtrée sur la catégorie de l’URL', () => {
        window.history.replaceState({}, '', '/exercises?category=Dos')

        const wrapper = mountPage([PECS, BACK, PECS_2])

        expect(renderedCards(wrapper)).toEqual([{ name: 'Tractions', category: 'Dos' }])

        wrapper.unmount()
    })

    it('écrit la catégorie choisie dans l’URL', async () => {
        const wrapper = mountPage([PECS, BACK])

        await wrapper.find('[dusk="category-pill-Dos"]').trigger('click')

        expect(new URL(window.location.href).searchParams.get('category')).toBe('Dos')

        wrapper.unmount()
    })

    it('retire le paramètre en revenant à « Tous »', async () => {
        window.history.replaceState({}, '', '/exercises?category=Dos')

        const wrapper = mountPage([PECS, BACK])

        await wrapper.find('[dusk="category-pill-all"]').trigger('click')

        expect(window.location.search).toBe('')

        wrapper.unmount()
    })

    it('ne déclenche pas de visite Inertia pour un filtrage client', async () => {
        const wrapper = mountPage([PECS, BACK])
        const { router } = await import('@inertiajs/vue3')

        await wrapper.find('[dusk="category-pill-Dos"]').trigger('click')

        expect(router.visit).not.toHaveBeenCalled()

        wrapper.unmount()
    })
})

describe('Exercises/Index — suppression optimiste', () => {
    /*
     * The row deleted is the first of two in the same category, deliberately.
     * Deleting across categories makes the rollback untestable: the grouping
     * re-sorts by category, so putting the row back at the end of the array
     * renders identically to putting it back at its index.
     */
    const deleteFirstCard = async (wrapper) => {
        wrapper.findAllComponents({ name: 'ExerciseCard' })[0].vm.$emit('delete', PECS.id)
        await wrapper.vm.$nextTick()
    }

    it('retire la ligne avant même la réponse du serveur', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true)

        const wrapper = mountPage([PECS, PECS_2, BACK])
        await deleteFirstCard(wrapper)

        expect(renderedCards(wrapper).map((card) => card.name)).toEqual(['Écarté couché', 'Tractions'])
        expect(hoisted.routerDelete).toHaveBeenCalledTimes(1)

        wrapper.unmount()
    })

    it('remet la ligne à sa place quand le serveur refuse', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true)

        const wrapper = mountPage([PECS, PECS_2, BACK])
        await deleteFirstCard(wrapper)

        const [, options] = hoisted.routerDelete.mock.calls[0]
        options.onError()
        await wrapper.vm.$nextTick()

        expect(renderedCards(wrapper).map((card) => card.name)).toEqual([
            'Développé couché',
            'Écarté couché',
            'Tractions',
        ])

        wrapper.unmount()
    })

    it('ne supprime rien si la confirmation est refusée', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(false)

        const wrapper = mountPage([PECS, PECS_2, BACK])
        await deleteFirstCard(wrapper)

        expect(hoisted.routerDelete).not.toHaveBeenCalled()
        expect(renderedCards(wrapper)).toHaveLength(3)

        wrapper.unmount()
    })
})

describe('Exercises/Index — divers', () => {
    it('traduit le type technique en libellé lisible pour la carte', () => {
        const wrapper = mountPage([PECS])

        const typeLabel = wrapper.findAllComponents({ name: 'ExerciseCard' })[0].props('typeLabel')

        expect(typeLabel('strength')).toBe('Force')
        expect(typeLabel('timed')).toBe('Temps')
        expect(typeLabel('inconnu')).toBe('inconnu')

        wrapper.unmount()
    })

    it('met le focus sur la recherche avec ⌘K', async () => {
        const wrapper = mountPage([PECS])

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true }))
        await wrapper.vm.$nextTick()

        expect(document.activeElement).toBe(wrapper.find('#search-exercises-input').element)

        wrapper.unmount()
    })

    /**
     * Échap vide la recherche, ce qu'il n'a jamais fait.
     *
     * La garde comparait `document.activeElement` à `searchInput.value`, or
     * cette ref est posée sur `GlassInput`, un composant : elle vaut le proxy
     * qu'il expose et jamais un élément du DOM. La condition était donc
     * insatisfiable et son corps mort. Et s'il avait été atteint, il appelait
     * `blur()`, absent du `defineExpose` — l'optional chaining ne protège que
     * la nullité de la ref, pas celle de la propriété, donc l'appel aurait levé.
     *
     * ⌘K marchait parce qu'il n'a pas de garde et n'appelle que `focus`, qui
     * était exposé. Le raccourci qui marche cachait celui qui ne marchait pas.
     */
    it('vide la recherche avec Échap quand le champ a le focus', async () => {
        const wrapper = mountPage([PECS, BACK])
        const field = wrapper.find('#search-exercises-input')

        await field.setValue('trac')
        field.element.focus()
        expect(document.activeElement).toBe(field.element)

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
        await wrapper.vm.$nextTick()

        expect(field.element.value).toBe('')
        expect(document.activeElement).not.toBe(field.element)

        wrapper.unmount()
    })

    it("laisse la recherche tranquille quand Échap arrive d'ailleurs", async () => {
        const wrapper = mountPage([PECS, BACK])
        const field = wrapper.find('#search-exercises-input')

        await field.setValue('trac')
        field.element.blur()

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
        await wrapper.vm.$nextTick()

        expect(field.element.value).toBe('trac')

        wrapper.unmount()
    })

    /*
     * Asserted through the listener registry rather than by dispatching a key
     * after unmount: the input is detached by then, so focusing it is a no-op
     * and the observable outcome is identical whether the listener leaked or
     * not. Identity of the handler is the point — removing a different function
     * leaves the old one attached.
     */
    it('retire l’écouteur clavier qu’elle avait posé, en quittant la page', () => {
        const addSpy = vi.spyOn(document, 'addEventListener')
        const wrapper = mountPage([PECS])

        const registration = addSpy.mock.calls.find(([type]) => type === 'keydown')
        expect(registration).toBeDefined()

        const removeSpy = vi.spyOn(document, 'removeEventListener')
        wrapper.unmount()

        expect(removeSpy).toHaveBeenCalledWith('keydown', registration[1])
    })

    it('affiche l’erreur serveur renvoyée par la page', () => {
        const wrapper = mount(ExercisesIndex, {
            props: { exercises: [PECS] },
            global: {
                directives: { press: {} },
                mocks: { $page: { props: { errors: { exercise: 'Exercice déjà utilisé' } } } },
                stubs: {
                    AuthenticatedLayout: passesSlot,
                    ExerciseCategoryChart: true,
                    ExerciseCard: ExerciseCardStub,
                },
            },
        })

        expect(wrapper.text()).toContain('Exercice déjà utilisé')

        wrapper.unmount()
    })
})

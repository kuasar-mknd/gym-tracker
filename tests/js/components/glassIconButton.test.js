import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import GlassIconButton from '@/Components/UI/GlassIconButton.vue'

const monter = (props = {}, options = {}) =>
    mount(GlassIconButton, {
        props: { icon: 'delete', label: "Supprimer l'entrée", ...props },
        ...options,
    })

describe('GlassIconButton', () => {
    /**
     * Le nom accessible est la seule chose qu'un lecteur d'écran a à annoncer :
     * la ligature est masquée, et il ne reste rien d'autre. Deux des boutons
     * remplacés par ce composant n'en portaient aucun.
     */
    it("porte le nom de l'action, et masque la ligature qui le remplace à l'œil", () => {
        const bouton = monter()

        expect(bouton.attributes('aria-label')).toBe("Supprimer l'entrée")
        expect(bouton.get('span').attributes('aria-hidden')).toBe('true')
        expect(bouton.get('span').text()).toBe('delete')
    })

    /**
     * `type` par défaut vaut `submit` dans un formulaire. Une corbeille posée
     * dans un formulaire enverrait donc le formulaire — c'est arrivé assez
     * souvent ailleurs pour que le composant le fixe une fois pour toutes.
     */
    it("ne soumet jamais le formulaire qui l'entoure", () => {
        expect(monter().attributes('type')).toBe('button')
    })

    /** Gris au repos, quelle que soit la gravité de ce qui suit. */
    it('ne prend sa couleur que sous le pointeur', () => {
        const classes = monter({ ton: 'danger' }).classes()

        expect(classes).toContain('text-text-muted')
        expect(classes).toContain('hover:text-accent-danger-deep')
        expect(classes).not.toContain('text-accent-danger-deep')
    })

    it('donne à chaque ton son propre survol', () => {
        expect(monter({ ton: 'neutre' }).classes()).toContain('hover:text-text-main')
        expect(monter({ ton: 'accent' }).classes()).toContain('hover:text-accent-primary-deep')
        expect(monter({ ton: 'info' }).classes()).toContain('hover:text-accent-info-deep')
    })

    /**
     * `compact` réduit la VIGNETTE, jamais la cible : le pseudo-élément porte
     * les 44 px que `size-6` ne fait pas. C'est la seule raison d'être de la
     * prop, et une régression y serait invisible à l'écran.
     */
    it('garde ses 44 px même en vignette réduite', () => {
        const normal = monter().classes()
        const compact = monter({ compact: true }).classes()

        expect(normal).toContain('min-h-touch')
        expect(normal).toContain('min-w-touch')

        expect(compact).toContain('size-6')
        expect(compact).toContain('before:-inset-2.5')
        expect(compact).not.toContain('min-h-touch')
    })

    it("laisse passer les attributs de l'appelant sans écraser les siens", () => {
        const bouton = monter({}, { attrs: { dusk: 'delete-entry', disabled: true } })

        expect(bouton.attributes('dusk')).toBe('delete-entry')
        expect(bouton.attributes('disabled')).toBeDefined()
        expect(bouton.classes()).toContain('min-h-touch')
    })

    it('refuse un ton qui ne fait pas partie de la charte', () => {
        const { validator } = GlassIconButton.props.ton

        expect(validator('danger')).toBe(true)
        expect(validator('rouge')).toBe(false)
    })
})

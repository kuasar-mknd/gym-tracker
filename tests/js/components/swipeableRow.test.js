import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import SwipeableRow from '@/Components/UI/SwipeableRow.vue'
import { evenementDeDoigt } from '../utils/geste'

/*
 * Une horloge et une file d'images à la main : le ressort avance quand le test
 * le dit, et les évènements de pointeur portent l'heure du test, comme ceux du
 * navigateur portent la leur.
 */
let horloge = 0
let file = new Map()
let numero = 0

const image = (ms = 16) => {
    horloge += ms
    const courantes = [...file.entries()]
    file = new Map()
    courantes.forEach(([, rappel]) => rappel(horloge))
}

const poser = async () => {
    for (let i = 0; i < 400 && file.size > 0; i += 1) {
        image()
    }
    await flushPromises()
}

const mountRow = (props = {}, slots = {}) =>
    mount(SwipeableRow, {
        props,
        slots: {
            'action-left': '<button type="button" data-testid="edit">Modifier</button>',
            'action-right': '<button type="button" data-testid="delete">Supprimer</button>',
            default: '<a href="/workouts/1">Séance</a>',
            ...slots,
        },
        attachTo: document.body,
    })

const mountDeleteOnlyRow = () =>
    mount(SwipeableRow, {
        slots: {
            'action-right': '<button type="button" data-testid="delete">Supprimer</button>',
            default: '<a href="/workouts/1">Séance</a>',
        },
        attachTo: document.body,
    })

const contenu = (wrapper) => wrapper.find('[data-glissant]')
const panneau = (wrapper, cote) => wrapper.find(`[data-panneau="${cote}"]`)

const positionDe = (wrapper) => {
    const transformation = contenu(wrapper).element.style.transform

    return transformation === '' ? 0 : Number.parseFloat(transformation.match(/translateX\((-?[\d.]+)px\)/)[1])
}

const largeurDe = (wrapper, cote) => Number.parseFloat(panneau(wrapper, cote).element.style.width)

/** Un doigt posé sur la rangée : `vers` le déplace, `leve` le retire. */
const doigt = (wrapper, depart, options = {}) => {
    const cible = contenu(wrapper).element
    const y = options.y ?? 10
    let courant = depart
    const envoyer = (type, x, haut = y) =>
        cible.dispatchEvent(evenementDeDoigt(type, { x, y: haut, horloge, ...options }))

    envoyer('pointerdown', depart)

    return {
        vers(x, ms = 16, haut = y) {
            horloge += ms
            courant = x
            envoyer('pointermove', x, haut)

            return this
        },
        leve(ms = 0) {
            horloge += ms
            envoyer('pointerup', courant)

            return this
        },
        annule() {
            envoyer('pointercancel', courant)

            return this
        },
    }
}

/** Ouvre la rangée comme le fait le clavier, puis laisse le ressort se poser. */
const ouvrirAuFocus = async (wrapper, cote) => {
    await wrapper.find(`[data-testid="${cote === 'droite' ? 'delete' : 'edit'}"]`).trigger('focusin')
    await poser()
}

beforeEach(() => {
    horloge = 1000
    file = new Map()
    numero = 0
    vi.stubGlobal('requestAnimationFrame', (rappel) => {
        numero += 1
        file.set(numero, rappel)

        return numero
    })
    vi.stubGlobal('cancelAnimationFrame', (id) => file.delete(id))
    vi.stubGlobal('matchMedia', (requete) => ({
        matches: false,
        media: requete,
        addEventListener() {},
        removeEventListener() {},
    }))
})

afterEach(() => {
    vi.unstubAllGlobals()
    document.body.innerHTML = ''
})

describe('SwipeableRow, au repos et au clavier', () => {
    it('est fermée, sans panneau visible, tant qu’on ne l’a pas ouverte', () => {
        const wrapper = mountRow()

        expect(wrapper.attributes('data-ouverte')).toBeUndefined()
        expect(positionDe(wrapper)).toBe(0)
        expect(largeurDe(wrapper, 'droite')).toBe(0)

        wrapper.unmount()
    })

    it('s’ouvre quand une action de droite prend le focus, et le dit sur la racine', async () => {
        const wrapper = mountRow()

        await ouvrirAuFocus(wrapper, 'droite')

        expect(positionDe(wrapper)).toBe(-80)
        expect(wrapper.attributes('data-ouverte')).toBe('droite')

        wrapper.unmount()
    })

    it('s’ouvre de l’autre côté pour une action de gauche', async () => {
        const wrapper = mountRow({ actionThreshold: 64 })

        await ouvrirAuFocus(wrapper, 'gauche')

        expect(positionDe(wrapper)).toBe(64)
        expect(wrapper.attributes('data-ouverte')).toBe('gauche')

        wrapper.unmount()
    })

    it('se referme quand le focus quitte les actions', async () => {
        const wrapper = mountRow()

        await ouvrirAuFocus(wrapper, 'droite')
        await wrapper.find('[data-testid="delete"]').trigger('focusout', { relatedTarget: document.body })
        await poser()

        expect(positionDe(wrapper)).toBe(0)
        expect(wrapper.attributes('data-ouverte')).toBeUndefined()

        wrapper.unmount()
    })

    it('reste ouverte quand le focus passe d’une action à l’autre de la même rangée', async () => {
        const wrapper = mountRow()
        const edit = wrapper.find('[data-testid="edit"]')
        const remove = wrapper.find('[data-testid="delete"]')

        await edit.trigger('focusin')
        await edit.trigger('focusout', { relatedTarget: remove.element })
        await poser()

        expect(positionDe(wrapper)).toBe(80)

        wrapper.unmount()
    })

    it('retire ses actions quand elle est coupée', () => {
        const wrapper = mountRow({ disabled: true })

        expect(wrapper.find('[data-testid="delete"]').exists()).toBe(false)

        wrapper.unmount()
    })

    it('se referme d’un coup quand elle est coupée ouverte', async () => {
        const wrapper = mountRow()

        await ouvrirAuFocus(wrapper, 'droite')
        await wrapper.setProps({ disabled: true })

        expect(positionDe(wrapper)).toBe(0)
        expect(wrapper.attributes('data-ouverte')).toBeUndefined()

        wrapper.unmount()
    })

    it('se referme par close()', async () => {
        const wrapper = mountRow()

        await ouvrirAuFocus(wrapper, 'droite')
        wrapper.vm.close()
        await poser()

        expect(positionDe(wrapper)).toBe(0)
        expect(wrapper.attributes('data-ouverte')).toBeUndefined()

        wrapper.unmount()
    })

    it('se referme quand on touche ailleurs, pas quand on touche la rangée', async () => {
        const wrapper = mountRow()

        await ouvrirAuFocus(wrapper, 'droite')

        contenu(wrapper).element.dispatchEvent(new Event('pointerdown', { bubbles: true }))
        await poser()
        expect(positionDe(wrapper)).toBe(-80)

        document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }))
        await poser()

        expect(positionDe(wrapper)).toBe(0)

        wrapper.unmount()
    })
})

describe('SwipeableRow, le panneau', () => {
    it('est détaché de la carte par 8 px, et suit son bord', async () => {
        const wrapper = mountRow()

        await ouvrirAuFocus(wrapper, 'droite')

        expect(largeurDe(wrapper, 'droite')).toBe(72)
        expect(largeurDe(wrapper, 'gauche')).toBe(0)

        wrapper.unmount()
    })

    it('grandit avec un tirage au-delà du cran, sans laisser de vide', async () => {
        const wrapper = mountRow({ actionThreshold: 64 })
        const main = doigt(wrapper, 300)

        main.vers(289)
        main.vers(100)

        const tire = Math.abs(positionDe(wrapper))

        expect(tire).toBeGreaterThan(64)
        expect(largeurDe(wrapper, 'droite')).toBeCloseTo(tire - 8, 5)

        main.leve()
        wrapper.unmount()
    })

    it('ne montre que l’action que le geste découvre', () => {
        const wrapper = mountRow()
        const main = doigt(wrapper, 100)

        main.vers(111)
        main.vers(180)

        expect(positionDe(wrapper)).toBeGreaterThan(0)
        expect(largeurDe(wrapper, 'gauche')).toBeGreaterThan(0)
        expect(largeurDe(wrapper, 'droite')).toBe(0)

        main.leve()
        wrapper.unmount()
    })

    it('fond l’icône et le libellé entre 24 et 56 px de largeur', async () => {
        const wrapper = mountRow()
        const fondu = () => Number.parseFloat(panneau(wrapper, 'droite').element.style.getPropertyValue('--fondu'))
        const main = doigt(wrapper, 300)

        main.vers(289)
        main.vers(289 - 28)
        expect(fondu()).toBeCloseTo(0, 5)

        main.vers(289 - 40)
        expect(fondu()).toBeCloseTo((32 - 24) / 32, 5)

        main.vers(289 - 70)
        expect(fondu()).toBe(1)

        main.leve()
        wrapper.unmount()
    })
})

describe('SwipeableRow, le geste', () => {
    it('ne suit pas un tirage vers un côté sans action', () => {
        const wrapper = mountDeleteOnlyRow()
        const main = doigt(wrapper, 100)

        main.vers(111)
        main.vers(260)

        expect(positionDe(wrapper)).toBe(0)
        expect(largeurDe(wrapper, 'droite')).toBe(0)

        main.leve()
        wrapper.unmount()
    })

    it('suit un tirage vers le côté qui en a une', () => {
        const wrapper = mountDeleteOnlyRow()
        const main = doigt(wrapper, 260)

        main.vers(249)
        main.vers(200)

        expect(positionDe(wrapper)).toBe(-49)

        main.leve()
        wrapper.unmount()
    })

    it('prend l’origine au verrou : la rangée ne saute pas de 11 px', () => {
        const wrapper = mountRow()
        const main = doigt(wrapper, 200)

        main.vers(211)

        expect(Math.abs(positionDe(wrapper))).toBeLessThanOrEqual(1)

        main.leve()
        wrapper.unmount()
    })

    it('ne verrouille pas un geste plutôt vertical, même s’il glisse ensuite sur le côté', () => {
        const wrapper = mountRow()
        const main = doigt(wrapper, 200)

        main.vers(205, 16, 30)
        main.vers(100, 16, 30)

        expect(positionDe(wrapper)).toBe(0)

        main.leve()
        wrapper.unmount()
    })

    it('suit le doigt à 1 pour 1, puis de plus en plus mollement au-delà du cran', () => {
        const wrapper = mountRow()
        const main = doigt(wrapper, 0)

        main.vers(11)
        main.vers(160)

        expect(positionDe(wrapper)).toBeGreaterThan(110)
        expect(positionDe(wrapper)).toBeLessThan(149)

        main.leve()
        wrapper.unmount()
    })

    it('repart d’où est la rangée ouverte : +30 depuis -80 donne -60, pas +30', async () => {
        const wrapper = mountRow()

        await ouvrirAuFocus(wrapper, 'droite')
        const main = doigt(wrapper, 200)

        main.vers(211)
        main.vers(231)

        expect(positionDe(wrapper)).toBe(-60)
        expect(positionDe(wrapper)).toBeLessThanOrEqual(0)

        main.leve()
        wrapper.unmount()
    })

    it('reste ouverte quand on la tire un peu plus loin, puis qu’on lâche', async () => {
        const wrapper = mountRow()

        await ouvrirAuFocus(wrapper, 'droite')
        const main = doigt(wrapper, 200)

        main.vers(211)
        main.vers(181, 200)
        main.leve(200)
        await poser()

        expect(wrapper.attributes('data-ouverte')).toBe('droite')
        expect(positionDe(wrapper)).toBe(-80)

        wrapper.unmount()
    })

    it('s’ouvre sur un coup sec de 25 px en 50 ms', async () => {
        const wrapper = mountRow()
        const main = doigt(wrapper, 200)

        main.vers(188, 16)
        main.vers(163, 50)
        main.leve(4)
        await poser()

        expect(wrapper.attributes('data-ouverte')).toBe('droite')
        expect(positionDe(wrapper)).toBe(-80)

        wrapper.unmount()
    })

    it('se referme quand on la retient à 45 px en revenant vers le bord', async () => {
        const wrapper = mountRow()

        await ouvrirAuFocus(wrapper, 'gauche')
        const main = doigt(wrapper, 100)

        main.vers(111, 16)
        main.vers(76, 50)
        main.leve(4)
        await poser()

        expect(wrapper.attributes('data-ouverte')).toBeUndefined()
        expect(positionDe(wrapper)).toBe(0)

        wrapper.unmount()
    })

    it('reste ouverte quand le doigt s’arrête à 45 px avant de lâcher', async () => {
        const wrapper = mountRow()

        await ouvrirAuFocus(wrapper, 'gauche')
        const main = doigt(wrapper, 100)

        main.vers(111, 16)
        main.vers(76, 50)
        main.leve(300)
        await poser()

        expect(wrapper.attributes('data-ouverte')).toBe('gauche')

        wrapper.unmount()
    })

    it('ne va jamais d’un côté à l’autre, même lancée', async () => {
        const wrapper = mountRow()

        await ouvrirAuFocus(wrapper, 'gauche')
        const main = doigt(wrapper, 300)

        main.vers(311, 16)
        main.vers(120, 40)
        main.leve(2)
        await poser()

        expect(wrapper.attributes('data-ouverte')).toBeUndefined()
        expect(positionDe(wrapper)).toBe(0)

        wrapper.unmount()
    })

    it('est à mi-chemin une image après le relâchement, entre le lâcher et le cran', () => {
        const wrapper = mountRow()
        const main = doigt(wrapper, 200)

        main.vers(188, 16)
        main.vers(158, 60)
        const lache = positionDe(wrapper)

        main.leve(4)
        image()

        const apres = positionDe(wrapper)

        expect(lache).toBeCloseTo(-30, 5)
        expect(apres).toBeLessThan(lache)
        expect(apres).toBeGreaterThan(-80)

        wrapper.unmount()
    })

    it('se pose pour de bon sur le cran, sans image restante', async () => {
        const wrapper = mountRow()
        const main = doigt(wrapper, 200)

        main.vers(188, 16)
        main.vers(100, 60)
        main.leve(4)
        await poser()

        expect(positionDe(wrapper)).toBe(-80)
        expect(file.size).toBe(0)

        wrapper.unmount()
    })

    it('reprend en vol : la saisie arrête le ressort et part de la position affichée', () => {
        const wrapper = mountRow()
        const main = doigt(wrapper, 200)

        main.vers(188, 16)
        main.vers(158, 60)
        main.leve(4)
        image()
        image()

        const enVol = positionDe(wrapper)
        const saisie = doigt(wrapper, 200)

        expect(file.size).toBe(0)
        saisie.vers(211)
        expect(positionDe(wrapper)).toBe(enVol)

        saisie.leve()
        wrapper.unmount()
    })

    it('rend la rangée à zéro quand le système reprend le geste', async () => {
        const wrapper = mountRow()
        const main = doigt(wrapper, 200)

        main.vers(188, 16)
        main.vers(158, 60)
        main.annule()
        await poser()

        expect(positionDe(wrapper)).toBe(0)
        expect(wrapper.attributes('data-ouverte')).toBeUndefined()

        wrapper.unmount()
    })

    it('cesse de suivre le doigt quand elle est coupée en plein geste', async () => {
        const wrapper = mountRow()
        const main = doigt(wrapper, 300)

        main.vers(289)
        main.vers(260)
        await wrapper.setProps({ disabled: true })
        main.vers(180)

        expect(positionDe(wrapper)).toBe(0)

        wrapper.unmount()
    })

    it('relâche la capture quand elle est coupée en plein geste', async () => {
        const wrapper = mountRow()
        const relache = vi.fn()

        contenu(wrapper).element.setPointerCapture = vi.fn()
        contenu(wrapper).element.releasePointerCapture = relache
        const main = doigt(wrapper, 300)

        main.vers(289)
        await wrapper.setProps({ disabled: true })

        expect(relache).toHaveBeenCalledWith(1)

        wrapper.unmount()
    })

    it('ne capture le pointeur qu’au verrou, pas à la saisie', () => {
        const wrapper = mountRow()
        const capture = vi.fn()

        contenu(wrapper).element.setPointerCapture = capture
        const main = doigt(wrapper, 300)

        expect(capture).not.toHaveBeenCalled()
        main.vers(305)
        expect(capture).not.toHaveBeenCalled()
        main.vers(289)
        expect(capture).toHaveBeenCalledWith(1)

        main.leve()
        wrapper.unmount()
    })

    it('ne pose aucun écouteur actif et garde touch-action: pan-y', () => {
        const wrapper = mountRow()

        expect(contenu(wrapper).classes()).toContain('touch-pan-y')

        wrapper.unmount()
    })

    it('ignore la souris, que le glisser natif du réordonnancement garde', () => {
        const wrapper = mountRow()
        const main = doigt(wrapper, 200, { pointerType: 'mouse' })

        main.vers(188)
        main.vers(100)

        expect(positionDe(wrapper)).toBe(0)

        main.leve()
        wrapper.unmount()
    })

    it('ne dépose aucune image en attente en mouvement réduit : pose directe sur la cible', async () => {
        vi.stubGlobal('matchMedia', (requete) => ({
            matches: requete.includes('reduce'),
            media: requete,
            addEventListener() {},
            removeEventListener() {},
        }))
        const wrapper = mountRow()
        const main = doigt(wrapper, 200)

        main.vers(188, 16)
        main.vers(100, 60)
        expect(positionDe(wrapper)).toBeLessThan(-80)

        main.leve(4)
        await flushPromises()

        expect(file.size).toBe(0)
        expect(positionDe(wrapper)).toBe(-80)
        expect(wrapper.attributes('data-ouverte')).toBe('droite')

        wrapper.unmount()
    })
})

describe('SwipeableRow, un doigt qui coupe l’animation', () => {
    it('relance le retour quand un tap le coupe en route, au lieu de figer la rangée', async () => {
        const wrapper = mountRow()

        await ouvrirAuFocus(wrapper, 'droite')
        wrapper.vm.close()
        for (let i = 0; i < 4; i += 1) {
            image()
        }
        const enRoute = positionDe(wrapper)

        expect(enRoute).toBeLessThan(0)
        expect(enRoute).toBeGreaterThan(-80)

        doigt(wrapper, 150).leve(40)
        await poser()

        expect(positionDe(wrapper)).toBe(0)
        expect(wrapper.attributes('data-ouverte')).toBeUndefined()

        wrapper.unmount()
    })

    it('reprend l’ouverture quand le doigt la coupe puis part en défilement vertical', async () => {
        const wrapper = mountRow()

        await wrapper.find('[data-testid="delete"]').trigger('focusin')
        image()
        image()

        const main = doigt(wrapper, 200)

        main.vers(205, 16, 30)
        main.leve(16)
        await poser()

        expect(positionDe(wrapper)).toBe(-80)

        wrapper.unmount()
    })
})

describe('SwipeableRow, le clic sans geste derrière lui', () => {
    afterEach(() => vi.restoreAllMocks())

    const cliquerLeLien = (wrapper) => {
        const clic = new MouseEvent('click', { bubbles: true, cancelable: true })

        wrapper.find('a').element.dispatchEvent(clic)

        return clic
    }

    it('laisse passer un clic de clavier ou de lecteur d’écran venu longtemps après un tap sur rangée ouverte', async () => {
        const wrapper = mountRow()
        let maintenant = 5000

        vi.spyOn(performance, 'now').mockImplementation(() => maintenant)
        await ouvrirAuFocus(wrapper, 'droite')
        doigt(wrapper, 150).leve(40)
        maintenant += 2000

        expect(cliquerLeLien(wrapper).defaultPrevented).toBe(false)

        wrapper.unmount()
    })

    it('laisse passer un clic de clavier venu longtemps après un glissement', () => {
        const wrapper = mountRow()
        let maintenant = 5000

        vi.spyOn(performance, 'now').mockImplementation(() => maintenant)
        const main = doigt(wrapper, 200)

        main.vers(188)
        main.vers(100, 60)
        main.leve()
        maintenant += 2000

        expect(cliquerLeLien(wrapper).defaultPrevented).toBe(false)

        wrapper.unmount()
    })

    it('garde l’avalement d’un tap sur rangée ouverte quand un deuxième doigt se pose', async () => {
        const wrapper = mountRow()

        await ouvrirAuFocus(wrapper, 'droite')
        const premier = doigt(wrapper, 150)

        doigt(wrapper, 100, { pointerId: 2 })
        premier.leve(40)

        expect(cliquerLeLien(wrapper).defaultPrevented).toBe(true)

        wrapper.unmount()
    })
})

describe('SwipeableRow, le clic qui suit un geste', () => {
    const cliquerLeLien = (wrapper) => {
        const clic = new MouseEvent('click', { bubbles: true, cancelable: true })

        wrapper.find('a').element.dispatchEvent(clic)

        return clic
    }

    it('est avalé après un glissement', () => {
        const wrapper = mountRow()
        const main = doigt(wrapper, 200)

        main.vers(188)
        main.vers(100, 60)
        main.leve()

        expect(cliquerLeLien(wrapper).defaultPrevented).toBe(true)
        expect(cliquerLeLien(wrapper).defaultPrevented).toBe(false)

        wrapper.unmount()
    })

    it('ferme la rangée ouverte et avale le clic d’un tap', async () => {
        const wrapper = mountRow()

        await ouvrirAuFocus(wrapper, 'droite')
        doigt(wrapper, 150).leve(40)
        const clic = cliquerLeLien(wrapper)
        await poser()

        expect(clic.defaultPrevented).toBe(true)
        expect(positionDe(wrapper)).toBe(0)
        expect(wrapper.attributes('data-ouverte')).toBeUndefined()

        wrapper.unmount()
    })

    it('laisse passer le clic d’un tap sur une rangée fermée', () => {
        const wrapper = mountRow()

        doigt(wrapper, 150).leve(40)

        expect(cliquerLeLien(wrapper).defaultPrevented).toBe(false)

        wrapper.unmount()
    })
})

/**
 * Un évènement de doigt (contact tactile) ou de pointeur (stylet, souris), daté par le test. `timeStamp` est en
 * lecture seule : le navigateur le pose, un test le redéfinit pour que la
 * vitesse du geste se lise sur l'horloge qu'il maîtrise.
 */
export const evenementDeDoigt = (type, { x = 0, y = 10, horloge = 0, pointerType = 'touch', pointerId = 1 } = {}) => {
    let evenement

    if (pointerType === 'touch') {
        const contact = { identifier: pointerId, clientX: x, clientY: y }
        const fin = type === 'pointerup' || type === 'pointercancel'

        evenement = new Event(type.replace('pointer', 'touch').replace('down', 'start').replace('up', 'end'), {
            bubbles: true,
            cancelable: true,
        })
        evenement.touches = fin ? [] : [contact]
        evenement.changedTouches = [contact]
    } else {
        evenement = new PointerEvent(type, {
            pointerId,
            pointerType,
            clientX: x,
            clientY: y,
            isPrimary: true,
            bubbles: true,
            cancelable: true,
        })
    }

    Object.defineProperty(evenement, 'timeStamp', { value: horloge })

    return evenement
}

/**
 * Des images d'animation que le test fait avancer : le ressort d'une rangée ne
 * bouge que lorsqu'il le dit.
 */
export const imagesALaMain = (vi) => {
    let file = new Map()
    let numero = 0
    let maintenant = 0

    vi.stubGlobal('requestAnimationFrame', (rappel) => {
        numero += 1
        file.set(numero, rappel)

        return numero
    })
    vi.stubGlobal('cancelAnimationFrame', (id) => file.delete(id))

    return {
        poser() {
            for (let i = 0; i < 400 && file.size > 0; i += 1) {
                const courantes = [...file.values()]

                file = new Map()
                maintenant += 16
                courantes.forEach((rappel) => rappel(maintenant))
            }
        },
    }
}

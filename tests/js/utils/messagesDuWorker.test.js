import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { creerUneFabriqueEnMemoire } from './baseIndexeeEnMemoire'
import { jsRoot } from '../conventions/sourceFiles'
import { TEMOIN, creerLeMagasinDesCopies, ouvrirLaBaseDuNavigateur } from '@/Utils/copiesHorsLigne'
import { CLEF_DU_TITULAIRE_DE_L_APPAREIL } from '@/Utils/historiqueDuCompte'
import { ATTENTE_DE_LA_LEVEE_MS, attendreLaReponse, installerLesMessagesDuWorker } from '@/Utils/messagesDuWorker'
import {
    MESSAGE_ATTENDRE_LA_REPONSE,
    MESSAGE_REPONSE_TARDIVE_ARRIVEE,
    MESSAGE_REPONSE_TARDIVE_RENVOYEE,
} from '@/sw/navigationsHorsLigne'

/*
 * Ce que la page fait des messages du worker (#2048, partie F) : une
 * redirection arrivée après le délai n'oublie les copies que si le témoin a
 * changé depuis le démarrage de la page, et « Attendre la réponse » lève le
 * délai avant de recharger. Le vrai magasin, sur une base en mémoire ; le
 * worker lui-même, et l'aller-retour avec la page, dans
 * `tests/js/app/navigationsDuWorker.test.js`.
 */

/** Une clé de 32 octets tirés au hasard, en base64url, comme le serveur la tire. */
const cleAuHasard = () =>
    btoa(String.fromCharCode(...crypto.getRandomValues(new Uint8Array(32))))
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '')

const poserLeTemoin = (cle) => {
    document.cookie = `${TEMOIN}=${cle}; path=/`
}

const retirerLeTemoin = () => {
    document.cookie = `${TEMOIN}=; path=/; max-age=0`
}

const accueil = {
    component: 'Dashboard',
    url: '/dashboard',
    version: 'version-des-actifs',
    encryptHistory: true,
    clearHistory: false,
    props: {
        auth: { user: { id: 7, name: 'Camille Horsligne', email: 'camille@example.org', active_workout: null } },
        errors: {},
        recentWorkouts: [{ id: 3, name: 'Séance du matin' }],
    },
}

/** Le conteneur des workers de la page : il reçoit leurs messages. */
const conteneurDesWorkers = () => new EventTarget()

/** Un message du worker, tel que la page le reçoit. */
const messageDuWorker = (donnees) => new MessageEvent('message', { data: donnees })

let fabrique
let magasin
let desinstaller

beforeEach(async () => {
    fabrique = creerUneFabriqueEnMemoire()
    magasin = creerLeMagasinDesCopies({ ouvrirLaBase: () => ouvrirLaBaseDuNavigateur(fabrique) })
    localStorage.setItem(CLEF_DU_TITULAIRE_DE_L_APPAREIL, 'compte:7')
    poserLeTemoin(cleAuHasard())
    expect(await magasin.ecrireUneCopie(accueil)).toBe('ecrite')
})

afterEach(async () => {
    desinstaller?.()
    desinstaller = undefined
    retirerLeTemoin()
    localStorage.clear()
    await magasin.fermer()
})

/**
 * La page démarrée, son écoute posée, et ce qu'elle a rechargé.
 */
const demarrerLaPage = async () => {
    const conteneur = conteneurDesWorkers()
    const recharger = vi.fn()
    desinstaller = installerLesMessagesDuWorker({ conteneur, magasin: () => magasin, recharger })

    // L'empreinte du témoin se relève au démarrage, avant toute réponse tardive.
    await vi.waitFor(async () => expect(await magasin.empreinteDuTemoin()).not.toBeNull())

    return { conteneur, recharger }
}

/** Ce que la page attend de la réponse au message : l'oubli éventuel, puis le rechargement. */
const laisserPasser = () => new Promise((resoudre) => setTimeout(resoudre, 50))

describe('une redirection arrivée après le délai', () => {
    it('oublie les copies et recharge quand la réponse a effacé le témoin', async () => {
        const { conteneur, recharger } = await demarrerLaPage()
        const avant = await magasin.empreinteDuTemoin()

        retirerLeTemoin()
        conteneur.dispatchEvent(messageDuWorker({ type: MESSAGE_REPONSE_TARDIVE_RENVOYEE }))
        await vi.waitFor(() => expect(recharger).toHaveBeenCalledTimes(1))

        // La copie est partie, et la clé de la session finie ne rouvrirait rien.
        poserLeTemoin(cleAuHasard())
        expect(await magasin.lireUneCopie('/dashboard')).toBeNull()
        expect(fabrique.enregistrements('gym-tracker-copies', 'etat').get('etat').empreintesFermees).toContain(avant)
    })

    it('oublie les copies quand la réponse a posé une autre clé', async () => {
        const { conteneur, recharger } = await demarrerLaPage()

        poserLeTemoin(cleAuHasard())
        conteneur.dispatchEvent(messageDuWorker({ type: MESSAGE_REPONSE_TARDIVE_RENVOYEE }))
        await vi.waitFor(() => expect(recharger).toHaveBeenCalledTimes(1))

        expect([...fabrique.enregistrements('gym-tracker-copies', 'pages').keys()]).toEqual([])
    })

    /*
     * `/` renvoie toujours vers l'accueil, session vivante : la redirection
     * seule ne dit rien, et la copie reste lisible.
     */
    it('ne fait rien quand le témoin n’a pas changé', async () => {
        const { conteneur, recharger } = await demarrerLaPage()

        conteneur.dispatchEvent(messageDuWorker({ type: MESSAGE_REPONSE_TARDIVE_RENVOYEE }))
        await laisserPasser()

        expect(recharger).not.toHaveBeenCalled()
        expect((await magasin.lireUneCopie('/dashboard'))?.page.props.recentWorkouts).toEqual([
            { id: 3, name: 'Séance du matin' },
        ])
    })

    /*
     * Sans témoin au démarrage, rien n'était lisible : recharger referait la
     * même navigation, le même délai et la même redirection, en boucle.
     */
    it('ne fait rien quand la page a démarré sans témoin', async () => {
        retirerLeTemoin()
        const conteneur = conteneurDesWorkers()
        const recharger = vi.fn()
        desinstaller = installerLesMessagesDuWorker({ conteneur, magasin: () => magasin, recharger })

        conteneur.dispatchEvent(messageDuWorker({ type: MESSAGE_REPONSE_TARDIVE_RENVOYEE }))
        await laisserPasser()

        expect(recharger).not.toHaveBeenCalled()
    })

    it('laisse les autres messages du worker', async () => {
        const { conteneur, recharger } = await demarrerLaPage()

        retirerLeTemoin()
        conteneur.dispatchEvent(messageDuWorker({ type: 'autre chose' }))
        conteneur.dispatchEvent(messageDuWorker(null))
        await laisserPasser()

        expect(recharger).not.toHaveBeenCalled()
        poserLeTemoin(cleAuHasard())
        expect([...fabrique.enregistrements('gym-tracker-copies', 'pages').keys()]).toHaveLength(1)
    })

    it('ne recharge pas quand le magasin se refuse', async () => {
        const conteneur = conteneurDesWorkers()
        const recharger = vi.fn()
        const refuse = {
            empreinteDuTemoin: () => Promise.reject(new DOMException('Refusé.', 'SecurityError')),
            oublierLesCopies: vi.fn(),
        }
        desinstaller = installerLesMessagesDuWorker({ conteneur, magasin: () => refuse, recharger })

        conteneur.dispatchEvent(messageDuWorker({ type: MESSAGE_REPONSE_TARDIVE_RENVOYEE }))
        await laisserPasser()

        expect(recharger).not.toHaveBeenCalled()
        expect(refuse.oublierLesCopies).not.toHaveBeenCalled()
    })

    it('n’écoute plus une fois désinstallée, et se passe d’un navigateur sans worker', async () => {
        const { conteneur, recharger } = await demarrerLaPage()

        desinstaller()
        retirerLeTemoin()
        conteneur.dispatchEvent(messageDuWorker({ type: MESSAGE_REPONSE_TARDIVE_RENVOYEE }))
        await laisserPasser()

        expect(recharger).not.toHaveBeenCalled()
        expect(installerLesMessagesDuWorker({ conteneur: null })).toBeTypeOf('function')
    })
})

/*
 * Le serveur était lent, pas absent : le worker a levé le délai de l'adresse,
 * puis prévient la page. Une page montée dans la coquille se recharge par une
 * visite d'Inertia, une coquille restée statique en entier.
 */
describe('une réponse réussie arrivée après le délai', () => {
    afterEach(() => {
        document.querySelector('script[data-page="app"]')?.remove()
    })

    it('note que le serveur répond quand une page est montée, sans recharger le document', async () => {
        const { noterQueLeServeurNeRepondPas, useEtatDuReseau } = await import('@/composables/useEtatDuReseau')
        const { conteneur, recharger } = await demarrerLaPage()
        const donnees = document.createElement('script')

        donnees.setAttribute('data-page', 'app')
        donnees.setAttribute('type', 'application/json')
        donnees.textContent = JSON.stringify(accueil)
        document.body.append(donnees)
        noterQueLeServeurNeRepondPas()

        conteneur.dispatchEvent(messageDuWorker({ type: MESSAGE_REPONSE_TARDIVE_ARRIVEE }))
        await laisserPasser()

        expect(useEtatDuReseau().joignable.value).toBe(true)
        expect(recharger).not.toHaveBeenCalled()
    })

    it('recharge une coquille restée statique, sans noter que le serveur répond', async () => {
        const conteneur = conteneurDesWorkers()
        const recharger = vi.fn()
        const serveurRepond = vi.fn()
        desinstaller = installerLesMessagesDuWorker({ conteneur, magasin: () => magasin, recharger, serveurRepond })

        conteneur.dispatchEvent(messageDuWorker({ type: MESSAGE_REPONSE_TARDIVE_ARRIVEE }))
        await laisserPasser()

        expect(recharger).toHaveBeenCalledTimes(1)
        expect(serveurRepond).not.toHaveBeenCalled()
    })

    it('n’oublie aucune copie, même le témoin changé', async () => {
        const { conteneur } = await demarrerLaPage()

        poserLeTemoin(cleAuHasard())
        conteneur.dispatchEvent(messageDuWorker({ type: MESSAGE_REPONSE_TARDIVE_ARRIVEE }))
        await laisserPasser()

        expect([...fabrique.enregistrements('gym-tracker-copies', 'pages').keys()]).toEqual(['/dashboard'])
    })
})

describe('« Attendre la réponse »', () => {
    it('demande au worker de lever le délai de l’adresse, et recharge à sa réponse', async () => {
        const recharger = vi.fn()
        const postMessage = vi.fn((_, [port]) => {
            port.postMessage({ type: MESSAGE_ATTENDRE_LA_REPONSE, levee: true })
        })

        await attendreLaReponse({ conteneur: { controller: { postMessage } }, adresse: '/dashboard', recharger })

        expect(postMessage).toHaveBeenCalledExactlyOnceWith(
            { type: MESSAGE_ATTENDRE_LA_REPONSE, adresse: '/dashboard' },
            [expect.any(MessagePort)],
        )
        expect(recharger).toHaveBeenCalledTimes(1)
    })

    it('recharge au bout d’une seconde quand le worker ne répond pas', async () => {
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
        const recharger = vi.fn()

        try {
            const attente = attendreLaReponse({
                conteneur: { controller: { postMessage: vi.fn() } },
                adresse: '/dashboard',
                recharger,
            })

            await vi.advanceTimersByTimeAsync(ATTENTE_DE_LA_LEVEE_MS - 1)
            expect(recharger).not.toHaveBeenCalled()
            await vi.advanceTimersByTimeAsync(1)
            await attente
        } finally {
            vi.useRealTimers()
        }

        expect(recharger).toHaveBeenCalledTimes(1)
    })

    it('recharge seulement, sans worker aux commandes ou quand l’envoi lève', async () => {
        const recharger = vi.fn()

        await attendreLaReponse({ conteneur: { controller: null }, adresse: '/dashboard', recharger })
        await attendreLaReponse({
            conteneur: {
                controller: {
                    postMessage: () => {
                        throw new DOMException('Le worker est parti.', 'InvalidStateError')
                    },
                },
            },
            adresse: '/dashboard',
            recharger,
        })

        expect(recharger).toHaveBeenCalledTimes(2)
    })
})

describe('le branchement', () => {
    /*
     * Le navigateur délivre les messages du worker dès la fin de l'analyse du
     * document, à qui écoute déjà : l'écoute se pose d'emblée, avant le
     * reste de ce que `main.js` installe.
     */
    it('est posé par main.js, avant tout le reste', () => {
        const main = readFileSync(resolve(jsRoot, 'main.js'), 'utf-8')
        const pose = main.indexOf('installerLesMessagesDuWorker()')

        expect(pose).toBeGreaterThan(-1)
        expect(pose).toBeLessThan(main.indexOf('inscrireLeWorker('))
        expect(pose).toBeLessThan(main.indexOf('createInertiaApp({'))
    })
})

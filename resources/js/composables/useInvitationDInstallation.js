import { computed, ref, shallowRef } from 'vue'

/**
 * L'invitation à installer l'application, une seule fois.
 *
 * L'application est une PWA complète — worker, manifeste, hors-ligne — et rien
 * n'invitait à l'installer (#1805). Sur Android, le navigateur propose
 * `beforeinstallprompt` et l'invite native fait le travail. Sur iPhone, il n'y
 * a pas d'évènement : l'installation passe par « Partager → Sur l'écran
 * d'accueil », que personne ne devine, donc on montre la marche à suivre.
 *
 * **Un évènement pour tout le document (#2114).** Chromium n'émet
 * `beforeinstallprompt` qu'au chargement du document : une visite d'Inertia ne
 * le relance pas, et il ne revient qu'après le rejet d'une bannière. L'accueil,
 * qui l'écoutait seul et le temps de son passage, perdait donc la carte au
 * premier aller-retour, l'invite du navigateur déjà écartée, et ne voyait
 * jamais celui émis sur une autre page. L'écoute se pose une fois pour le
 * document (`installerLInvitationDInstallation`, depuis `main.js`), garde
 * l'évènement dans ce module, et la carte ne fait plus que le lire.
 */
const CLEF_REFUS = 'gym-tracker:invitation-installation-refusee'

/**
 * L'évènement du navigateur, gardé pour tout le document jusqu'à
 * l'installation ou au refus. `shallowRef` : un évènement natif n'a rien à
 * suivre en profondeur.
 *
 * @type {import('vue').ShallowRef<Event|null>}
 */
const invitationDuNavigateur = shallowRef(null)

/**
 * Le refus de ce document : il tient même quand le stockage refuse de le
 * retenir, jusqu'au prochain chargement.
 */
const refuseeDansCeDocument = ref(false)

const estDejaInstallee = () =>
    window.matchMedia?.('(display-mode: standalone)')?.matches === true || window.navigator.standalone === true

const estUniPhone = () => /iPhone|iPad|iPod/.test(window.navigator.userAgent) && !window.MSStream

const aDejaRefuse = () => {
    if (refuseeDansCeDocument.value) {
        return true
    }

    try {
        return window.localStorage.getItem(CLEF_REFUS) !== null
    } catch {
        // Navigation privée, stockage bloqué : l'invitation reste possible.
        return false
    }
}

/**
 * L'invite du navigateur est toujours écartée, refus compris : sans cela,
 * Chrome affiche sa propre barre en bas de page, par-dessus la carte, ou après
 * « Ne plus proposer l'installation ». L'évènement ne se garde que s'il reste
 * quelque chose à proposer.
 *
 * @param {Event} evenement `beforeinstallprompt`
 */
const surInvitation = (evenement) => {
    evenement.preventDefault()

    if (!aDejaRefuse()) {
        invitationDuNavigateur.value = evenement
    }
}

/** Installée, par la carte ou par le menu du navigateur : plus rien à proposer. */
const surInstallation = () => {
    invitationDuNavigateur.value = null
}

/**
 * Écoute `beforeinstallprompt` et `appinstalled` pour tout le document.
 * `main.js` l'appelle une fois, d'emblée : le navigateur émet l'évènement sur
 * la page où il veut, coquille hors ligne comprise. Poser l'écoute deux fois ne
 * la double pas : ce sont les mêmes fonctions.
 *
 * Rend de quoi ne plus écouter, qui oublie aussi ce qui a été capté : un test
 * repart ainsi d'un document neuf.
 *
 * @returns {() => void}
 */
export const installerLInvitationDInstallation = () => {
    window.addEventListener('beforeinstallprompt', surInvitation)
    window.addEventListener('appinstalled', surInstallation)

    return () => {
        window.removeEventListener('beforeinstallprompt', surInvitation)
        window.removeEventListener('appinstalled', surInstallation)
        invitationDuNavigateur.value = null
        refuseeDansCeDocument.value = false
    }
}

export const useInvitationDInstallation = () => {
    /** `native` : le navigateur sait installer. `ios` : il faut expliquer. */
    const forme = estUniPhone() ? 'ios' : 'native'

    /** Lu une fois au montage : l'application déjà installée, un refus déjà rangé. */
    const sansObjet = estDejaInstallee() || aDejaRefuse()

    const visible = computed(() => {
        if (sansObjet || refuseeDansCeDocument.value) {
            return false
        }

        return forme === 'ios' || invitationDuNavigateur.value !== null
    })

    const refuser = () => {
        refuseeDansCeDocument.value = true
        invitationDuNavigateur.value = null

        try {
            window.localStorage.setItem(CLEF_REFUS, '1')
        } catch {
            // Rien à faire : elle reviendra au prochain chargement, pas plus.
        }
    }

    /**
     * L'invite native ne s'ouvre qu'une fois par évènement : il est consommé
     * pour tout le document dès l'appui, avant qu'elle s'ouvre, et un second
     * appui n'appelle pas `prompt()` une seconde fois.
     */
    const installer = async () => {
        const evenement = invitationDuNavigateur.value

        if (evenement === null) {
            return
        }

        invitationDuNavigateur.value = null
        evenement.prompt()
        await evenement.userChoice
    }

    return { visible, forme: computed(() => forme), installer, refuser }
}

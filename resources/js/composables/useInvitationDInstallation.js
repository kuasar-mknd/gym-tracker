import { onMounted, onUnmounted, ref } from 'vue'

/**
 * L'invitation à installer l'application, une seule fois.
 *
 * L'application est une PWA complète — worker, manifeste, hors-ligne — et rien
 * n'invitait à l'installer (#1805). Sur Android, le navigateur propose
 * `beforeinstallprompt` et l'invite native fait le travail. Sur iPhone, il n'y
 * a pas d'évènement : l'installation passe par « Partager → Sur l'écran
 * d'accueil », que personne ne devine, donc on montre la marche à suivre.
 */
const CLEF_REFUS = 'gym-tracker:invitation-installation-refusee'

const estDejaInstallee = () =>
    window.matchMedia?.('(display-mode: standalone)')?.matches === true || window.navigator.standalone === true

const estUniPhone = () => /iPhone|iPad|iPod/.test(window.navigator.userAgent) && !window.MSStream

const aDejaRefuse = () => {
    try {
        return window.localStorage.getItem(CLEF_REFUS) !== null
    } catch {
        // Navigation privée, stockage bloqué : l'invitation reste possible.
        return false
    }
}

export const useInvitationDInstallation = () => {
    const invitationNative = ref(null)
    const visible = ref(false)
    /** `native` : le navigateur sait installer. `ios` : il faut expliquer. */
    const forme = ref('native')

    const surInvitation = (evenement) => {
        // Sans cela, Chrome affiche sa propre barre en bas de page.
        evenement.preventDefault()
        invitationNative.value = evenement
        forme.value = 'native'
        visible.value = true
    }

    const surInstallation = () => {
        visible.value = false
        invitationNative.value = null
    }

    const refuser = () => {
        visible.value = false

        try {
            window.localStorage.setItem(CLEF_REFUS, '1')
        } catch {
            // Rien à faire : elle reviendra à la prochaine visite, pas plus.
        }
    }

    const installer = async () => {
        if (invitationNative.value === null) {
            return
        }

        invitationNative.value.prompt()
        await invitationNative.value.userChoice
        visible.value = false
        invitationNative.value = null
    }

    onMounted(() => {
        if (estDejaInstallee() || aDejaRefuse()) {
            return
        }

        if (estUniPhone()) {
            forme.value = 'ios'
            visible.value = true

            return
        }

        window.addEventListener('beforeinstallprompt', surInvitation)
        window.addEventListener('appinstalled', surInstallation)
    })

    onUnmounted(() => {
        window.removeEventListener('beforeinstallprompt', surInvitation)
        window.removeEventListener('appinstalled', surInstallation)
    })

    return { visible, forme, installer, refuser }
}

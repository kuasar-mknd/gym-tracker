import { onMounted, onUnmounted } from 'vue'

/**
 * Un raccourci clavier qui se tait pendant qu'on écrit.
 *
 * La règle vaut pour tous : un raccourci sans modificateur — `?` pour la page
 * des raccourcis — vole la frappe s'il écoute pendant qu'un champ a le focus,
 * et ceux avec modificateur gênent les raccourcis du navigateur. Le filtre est
 * ici, une fois, plutôt que dans chaque appelant.
 */
const dansUnChamp = (cible) =>
    cible instanceof HTMLElement && (['INPUT', 'TEXTAREA', 'SELECT'].includes(cible.tagName) || cible.isContentEditable)

/**
 * @param {(evenement: KeyboardEvent) => boolean} correspond Ce qui reconnaît la combinaison.
 * @param {(evenement: KeyboardEvent) => void} action Ce qu'elle déclenche.
 */
export const useRaccourciClavier = (correspond, action) => {
    const surTouche = (evenement) => {
        if (dansUnChamp(evenement.target) || !correspond(evenement)) {
            return
        }

        evenement.preventDefault()
        action(evenement)
    }

    onMounted(() => window.addEventListener('keydown', surTouche))
    onUnmounted(() => window.removeEventListener('keydown', surTouche))
}

/** `⌘` sur un Mac, `Ctrl` ailleurs : les deux valent la touche de commande. */
export const avecCommande = (evenement) => evenement.metaKey || evenement.ctrlKey

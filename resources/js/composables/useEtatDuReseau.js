import { readonly, ref } from 'vue'

/**
 * Si le serveur répond, d'après ce que l'application a vu (#2048).
 *
 * Un geste qui ne vaut qu'avec le serveur se désactive quand il ne répond pas :
 * « Recharger » une nouvelle version, qui remplacerait la séance ouverte par la
 * page « hors ligne » du worker, les réglages de la séance et l'enregistrement
 * comme modèle.
 *
 * `joignable` devient faux sur un échec réseau, d'une visite d'Inertia comme
 * d'une requête de `http()`, et sur l'évènement `offline` du navigateur. Il
 * redevient vrai sur toute réponse réussie : une visite d'Inertia, une requête
 * de `http()`, dont le rejeu de la file hors ligne, ou la sonde que la page
 * demande tant qu'il est faux (`Utils/sondeDuServeur.js`). Jamais sur le seul
 * `navigator.onLine`, ni sur l'évènement `online` : ils décrivent l'interface
 * réseau de l'appareil, pas le serveur, et un réseau qui ne répond plus laisse
 * l'appareil se croire en ligne.
 *
 * Il devient faux aussi quand la page montre une copie hors ligne (#2048,
 * partie E, `Utils/visitesHorsLigne.js`) : servie pour un réseau lent, elle
 * ne vient d'aucune réponse, et la sonde doit alors demander au serveur s'il
 * répond.
 *
 * L'état vit au niveau du module : il vaut pour toute la page, et survit au
 * remontage d'un composant à chaque navigation.
 */
const joignable = ref(true)

/** Ce qui suit chaque réponse du serveur : `surUneReponseDuServeur`. */
const rappelsDesReponses = new Set()

/**
 * Le serveur vient de répondre. Ce qui suit les réponses en est prévenu,
 * chaque fois, même quand `joignable` était déjà vrai.
 */
export const noterQueLeServeurRepond = () => {
    joignable.value = true

    for (const rappel of rappelsDesReponses) {
        try {
            rappel()
        } catch {
            // Un rappel qui échoue n'empêche ni les autres ni la réponse.
        }
    }
}

/** Une requête n'a reçu aucune réponse, ou le navigateur annonce la perte du réseau. */
export const noterQueLeServeurNeRepondPas = () => {
    joignable.value = false
}

/**
 * Appelle `rappel` à chaque réponse réussie du serveur (visite d'Inertia,
 * `http()` et rejeu de la file, sonde) : ce qui attend le retour du réseau,
 * une copie à recharger par exemple (`Utils/visitesHorsLigne.js`). Rend de
 * quoi ne plus suivre.
 *
 * @param {() => void} rappel appelé dans la réponse même : il ne lance rien de
 *   lourd sans rendre la main d'abord
 * @returns {() => void}
 */
export const surUneReponseDuServeur = (rappel) => {
    rappelsDesReponses.add(rappel)

    return () => rappelsDesReponses.delete(rappel)
}

/** @returns {{ joignable: Readonly<import('vue').Ref<boolean>> }} */
export const useEtatDuReseau = () => ({ joignable: readonly(joignable) })

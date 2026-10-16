/**
 * Une mise à jour optimiste revient sur tout échec (#2077).
 *
 * Une page qui change son écran avant la réponse du serveur (une ligne
 * retirée, un mois ou une période déjà choisis, un interrupteur déjà basculé)
 * doit le défaire quand la visite n'aboutit pas. Les pages le faisaient dans
 * `onError`, qu'Inertia n'appelle que sur des erreurs de validation : sans
 * réseau (`onNetworkError`), sur une réponse en erreur sans erreurs de
 * validation (`onHttpException` : 403, 404, 419, 429, 500, 503), sur une
 * visite annulée (`router.cancelAll()`) ou interrompue par une autre visite au
 * premier plan, rien ne revenait, et l'écran montrait jusqu'au prochain
 * chargement ce que le serveur n'avait pas fait.
 *
 * `revenirSiLaVisiteEchoue` enveloppe les options d'une visite : `revenir`
 * part une fois, à la fin de la visite (`onFinish`, qu'Inertia appelle à toute
 * issue, annulation comprise), si la visite n'a pas réussi (`onSuccess`), et
 * aussi quand elle n'est jamais partie, un écouteur `before` l'ayant arrêtée
 * (Inertia n'appelle alors aucun rappel, `onFinish` compris). Une réponse qui
 * recharge toute la page (409 `X-Inertia-Location`) n'est pas un échec. Les
 * messages restent ceux de toujours : sans réseau, le filet des visites
 * (`visitesHorsLigne.js`) dit ce qui n'est pas confirmé ; `revenir` ne dit
 * rien de plus.
 *
 * Pourquoi pas l'option `optimistic` d'Inertia 3 : elle ne change que les
 * props de la page, alors que l'état à défaire vit souvent dans une copie
 * locale ou hors des props (le mois demandé, la période, l'interrupteur) ; elle
 * rend la visite asynchrone par défaut ; et, en 3.9.1, un rechargement partiel
 * qui répond pendant qu'elle attend (les props différées d'une page, chargées
 * juste après son ouverture) recopie dans sa base la valeur déjà changée :
 * l'échec qui suit ne remet plus rien. `tests/js/utils/miseAJourOptimiste.test.js`
 * le constate sur la version installée.
 */

/**
 * Les options d'une visite d'Inertia qui appellent `revenir` sur tout échec.
 *
 * Une enveloppe par visite : elle compte les visites qu'elle a vues partir et
 * finir. Une réponse 409 `X-Inertia-Redirect` relance la visite avec les mêmes
 * rappels, et la seconde part avant que la première finisse : le compte
 * attend la fin de la seconde.
 *
 * Inertia appelle `onBefore`, puis émet `inertia:before` sur le document ; un
 * écouteur qui l'annule arrête la visite sans autre rappel. L'enveloppe
 * écoute cet évènement après tous les autres, posée dans `onBefore` même, et
 * lit s'il a été annulé.
 *
 * Une réponse 409 `X-Inertia-Location` recharge toute la page, sans
 * `onSuccess` ni rappel d'échec : c'est ce que reçoit une suppression faite
 * quand la version des actifs a changé depuis l'ouverture de la page (la
 * redirection qui la suit répond ainsi), et la page rechargée montre ce que
 * le serveur a fait. Inertia émet alors `inertia:location`, que l'enveloppe
 * écoute tant que sa visite est en vol. L'évènement ne dit pas quelle visite
 * l'a reçu : un rechargement en arrière-plan renvoyé de même (Inertia ne
 * recharge pas la page pour une visite asynchrone) ne doit pas faire passer
 * pour réussie une visite qui échoue. Le renvoi ne compte donc que pour une
 * visite allée à son terme sans qu'aucun rappel d'échec (`onError`,
 * `onHttpException`, `onNetworkError`) n'ait parlé.
 *
 * @template {Record<string, unknown>} Options
 * @param {() => void} revenir remet l'écran où il était avant la visite
 * @param {Options} options les options de la visite, rappels compris
 * @returns {Options} les mêmes options, `onBefore`, `onSuccess`, `onFinish` et les rappels d'échec enveloppés
 */
export const revenirSiLaVisiteEchoue = (revenir, options = {}) => {
    /** Les visites parties et pas encore finies. */
    let enVol = 0

    let reussie = false
    let echouee = false
    let renvoyee = false
    let revenue = false

    const surRenvoi = () => {
        renvoyee = true
    }

    const conclure = () => {
        if (enVol > 0) {
            return
        }

        if (typeof document !== 'undefined') {
            document.removeEventListener('inertia:location', surRenvoi)
        }

        if (reussie || revenue || (renvoyee && !echouee)) {
            return
        }

        revenue = true
        revenir()
    }

    /**
     * Le rappel d'échec `nom`, qui note l'échec et rend ce que rend celui de
     * l'appelant : `false` y tait la fenêtre d'erreur ou le filet des visites.
     *
     * @param {'onError' | 'onHttpException' | 'onNetworkError'} nom
     */
    const noterLEchec =
        (nom) =>
        (...details) => {
            echouee = true

            return options[nom]?.(...details)
        }

    return {
        ...options,
        onBefore: (visite) => {
            const verdict = options.onBefore?.(visite)

            // Inertia s'arrête ici, sans émettre `inertia:before`.
            if (verdict === false) {
                conclure()

                return false
            }

            enVol += 1

            if (typeof document !== 'undefined') {
                document.addEventListener('inertia:location', surRenvoi)
                document.addEventListener(
                    'inertia:before',
                    (evenement) => {
                        if (evenement.defaultPrevented) {
                            enVol = Math.max(0, enVol - 1)
                            conclure()
                        }
                    },
                    { once: true },
                )
            }

            return verdict
        },
        onSuccess: (page) => {
            reussie = true

            return options.onSuccess?.(page)
        },
        onError: noterLEchec('onError'),
        onHttpException: noterLEchec('onHttpException'),
        onNetworkError: noterLEchec('onNetworkError'),
        onFinish: (visite) => {
            if (visite?.cancelled || visite?.interrupted) {
                echouee = true
            }

            enVol = Math.max(0, enVol - 1)
            conclure()

            return options.onFinish?.(visite)
        },
    }
}

/**
 * Retire de la liste l'élément de clé `cle`, et rend de quoi le remettre à sa
 * place exacte, ou `null` s'il n'y est pas.
 *
 * Remettre à l'indice de départ trompait dès qu'une autre ligne bougeait
 * entre-temps : deux suppressions en vol qui échouent dans l'ordre inverse
 * remettaient la seconde avant la première. L'élément revient donc devant le
 * premier de ses suivants d'alors encore présent, sinon derrière le plus
 * proche de ses précédents, sinon à son indice, borné. Il ne revient pas s'il
 * est déjà là : une réponse du serveur a pu rendre la liste entre-temps.
 *
 * La liste se relit à chaque appel : une page qui recopie ses props remplace
 * le tableau à chaque réponse.
 *
 * @template Element
 * @param {() => Element[]} lireLaListe rend le tableau courant
 * @param {unknown} cle la clé de l'élément à retirer
 * @param {{ cleDe?: (element: Element) => unknown }} options
 * @returns {(() => void) | null}
 */
export const retirerDeLaListe = (lireLaListe, cle, { cleDe = (element) => element.id } = {}) => {
    const liste = lireLaListe()
    const indice = liste.findIndex((element) => cleDe(element) === cle)

    if (indice === -1) {
        return null
    }

    const suivants = liste.slice(indice + 1).map(cleDe)
    const precedents = liste.slice(0, indice).map(cleDe).reverse()
    const [retire] = liste.splice(indice, 1)

    return () => {
        const actuelle = lireLaListe()
        const cles = actuelle.map(cleDe)

        if (cles.includes(cle)) {
            return
        }

        const suivant = suivants.find((candidat) => cles.includes(candidat))

        if (suivant !== undefined) {
            actuelle.splice(cles.indexOf(suivant), 0, retire)

            return
        }

        const precedent = precedents.find((candidat) => cles.includes(candidat))

        if (precedent !== undefined) {
            actuelle.splice(cles.indexOf(precedent) + 1, 0, retire)

            return
        }

        actuelle.splice(Math.min(indice, actuelle.length), 0, retire)
    }
}

/**
 * Les éléments dans l'ordre que le serveur a confirmé, sans en perdre un.
 *
 * Un ordre refusé revenait à l'ordre confirmé en ne gardant que les éléments
 * que cet ordre connaissait : une ligne ou une série ajoutée depuis, ou dont
 * l'identifiant provisoire est devenu celui du serveur, disparaissait de
 * l'écran alors qu'elle existait. Ceux que l'ordre confirmé ne connaît pas
 * restent, à la fin et dans l'ordre de l'écran : le serveur range un ajout
 * après les autres.
 *
 * @template Element
 * @param {Element[]} elements dans l'ordre de l'écran
 * @param {unknown[]} ordreConfirme les clés, dans l'ordre que le serveur a confirmé
 * @param {{ cleDe?: (element: Element) => unknown }} options
 * @returns {Element[]}
 */
export const ordreRetabli = (elements, ordreConfirme, { cleDe = (element) => element.id } = {}) => {
    const parCle = new Map(elements.map((element) => [cleDe(element), element]))
    const confirmes = ordreConfirme.filter((cle) => parCle.has(cle)).map((cle) => parCle.get(cle))
    const connus = new Set(ordreConfirme)

    return [...confirmes, ...elements.filter((element) => !connus.has(cleDe(element)))]
}

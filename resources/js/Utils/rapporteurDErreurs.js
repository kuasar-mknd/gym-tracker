/**
 * Ce que Sentry recevait du navigateur, gardé chez nous : les erreurs non
 * rattrapées, les promesses rejetées et les erreurs de rendu Vue partent
 * vers `erreurs-navigateur.store`, une fois par empreinte et dix fois au
 * plus par page. Un envoi qui échoue se tait : rapporter une erreur ne
 * doit jamais en provoquer une.
 */
const LIMITE_PAR_PAGE = 10

export const envoyerParFetch = (charge) =>
    fetch(route('erreurs-navigateur.store'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify(charge),
        keepalive: true,
    })

export function installerLeRapporteurDErreurs(app, { envoyer = envoyerParFetch } = {}) {
    const empreintes = new Set()
    let envoyes = 0

    const rapporter = (type, message, source = null, ligne = null, colonne = null, pile = null) => {
        const texte = String(message ?? '').slice(0, 2000)

        if (texte === '') {
            return false
        }

        const empreinte = `${type}|${texte}|${source ?? ''}|${ligne ?? ''}`

        if (empreintes.has(empreinte) || envoyes >= LIMITE_PAR_PAGE) {
            return false
        }

        empreintes.add(empreinte)
        envoyes++

        Promise.resolve()
            .then(() =>
                envoyer({
                    type,
                    message: texte,
                    source,
                    ligne,
                    colonne,
                    pile: pile ? String(pile).slice(0, 20000) : null,
                    url: window.location.href,
                    agent: navigator.userAgent,
                }),
            )
            .catch(() => {})

        return true
    }

    window.addEventListener('error', (evenement) => {
        rapporter(
            'error',
            evenement.message,
            evenement.filename || null,
            evenement.lineno || null,
            evenement.colno || null,
            evenement.error?.stack,
        )
    })

    window.addEventListener('unhandledrejection', (evenement) => {
        const raison = evenement.reason
        rapporter('unhandledrejection', raison?.message ?? String(raison), null, null, null, raison?.stack)
    })

    const precedent = app.config.errorHandler
    app.config.errorHandler = (erreur, instance, info) => {
        rapporter('vue', erreur?.message ?? String(erreur), info, null, null, erreur?.stack)

        if (typeof precedent === 'function') {
            precedent(erreur, instance, info)
        } else {
            console.error(erreur)
        }
    }

    return { rapporter }
}

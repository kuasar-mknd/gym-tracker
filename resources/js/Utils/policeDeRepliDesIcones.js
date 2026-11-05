import policeVide from '../../fonts/icones-en-attente.woff2?inline'

/**
 * La police que la règle `material-symbols-outlined` d'`app.css` nomme après
 * Material Symbols (#2193). Chaque caractère ASCII imprimable y est un glyphe
 * vide, d'avance nulle, aux métriques verticales de Material Symbols : tant
 * que celle-ci n'est pas là, pendant sa période de blocage comme après, ou si
 * elle est refusée, le nom d'une icône (« notifications ») ne s'écrit pas et
 * ne prend aucune place. Son carré d'un em est tenu par `min-width`.
 */
export const FAMILLE_DE_REPLI_DES_ICONES = 'Icones en attente'

/**
 * Pose la police de repli des icônes dans le document, chargée dès cet appel.
 *
 * Construite depuis ses octets (`?inline` : Vite les écrit dans le module), une
 * `FontFace` est chargée à sa création, sans requête. Déclarée en CSS, même en
 * data:, elle ne se chargeait qu'à son premier usage, une tâche plus tard :
 * la première mise en page des icônes composait alors leur nom dans la police
 * par défaut, invisible pendant la période de blocage mais de toute sa
 * longueur, et la page débordait le temps d'une image ou deux (relevé dans
 * Chromium, processeur ralenti). `main.js` l'appelle donc avant de monter
 * l'application. Un navigateur sans `FontFace` garde le mode `block` de
 * Material Symbols (`fonts.css`).
 *
 * @param {FontFaceSet | undefined} polices L'ensemble des polices du document.
 * @returns {boolean} Vrai si la police est posée.
 */
export const installerLaPoliceDeRepliDesIcones = (polices = globalThis.document?.fonts) => {
    if (polices === undefined || globalThis.FontFace === undefined) {
        return false
    }

    const octets = Uint8Array.from(atob(policeVide.slice(policeVide.indexOf(',') + 1)), (caractere) =>
        caractere.charCodeAt(0),
    )
    polices.add(new FontFace(FAMILLE_DE_REPLI_DES_ICONES, octets))

    return true
}

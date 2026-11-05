/**
 * Ce qui vouvoie dans un texte français : l'application tutoie (#1980), ses
 * écrans comme ses messages (#2119).
 *
 * Un mot qui vouvoie se reconnaît seul : « vous », « votre », « vos »,
 * « vôtre », « êtes », et toute forme en « -ez », qui est en français celle
 * de la deuxième personne du pluriel (« Veuillez », « Commencez »,
 * « Terminez-la »). Quatre mots courants finissent en « -ez » sans en être
 * une : « chez », « assez », « nez » et « rez ». « faites » et « dites » n'y
 * sont pas : l'application les écrit en participes (« les modifications
 * faites hors ligne »).
 *
 * Le même motif garde le serveur et les courriels côté Pest
 * (`tests/Support/Vouvoiement.php`) : changer l'un, c'est changer l'autre.
 */
export const VOUVOIEMENT =
    /(?<![\p{L}\p{N}_])(?!(?:chez|assez|nez|rez)(?![\p{L}\p{N}_]))(?:vous|votre|vos|vôtres?|êtes|\p{L}*ez)(?![\p{L}\p{N}_])/iu

/**
 * Les mots de `texte` qui vouvoient, dans l'ordre.
 *
 * @param {string} texte
 * @returns {string[]}
 */
export const motsQuiVouvoient = (texte) =>
    [...texte.matchAll(new RegExp(VOUVOIEMENT.source, 'giu'))].map((trouve) => trouve[0])

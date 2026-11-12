/**
 * Le texte en minuscules et sans diacritiques, pour comparer une recherche à un
 * nom : « developpe » doit trouver « Développé couché ».
 *
 * @param {string} texte
 * @returns {string}
 */
export const sansAccents = (texte) => texte.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase()

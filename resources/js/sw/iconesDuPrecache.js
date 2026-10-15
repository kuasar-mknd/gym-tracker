import { createHash } from 'node:crypto'
import { readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'

/**
 * Les icônes de public/, au precache (#2048).
 *
 * Le motif `*.{ico,png,svg,webp}` des globs du precache ne trouvait rien : il
 * cherche dans public/build, et ces fichiers vivent à la racine de public/,
 * servis tels quels. Le logo, les icônes de l'application installée, celles
 * des notifications et le favicon manquaient donc sans réseau, et la
 * construction le signalait d'un avertissement que personne ne lisait.
 *
 * Ils entrent au precache par `additionalManifestEntries`, à leur adresse, avec
 * pour révision l'empreinte SHA-256 de leur contenu : une icône redessinée est
 * téléchargée de nouveau, une icône inchangée ne l'est pas. L'étape
 * `frontend-builder` du Dockerfile les copie, pour que l'image construise le
 * même worker que la CI.
 */

/** Les fichiers de la racine de public/ qui sont des icônes. */
export const MOTIF_DES_ICONES = /\.(ico|png|svg|webp)$/

/**
 * @param {string} dossierPublic le chemin de public/
 * @returns {Array<{ url: string, revision: string }>} triées par adresse
 */
export const iconesDuPrecache = (dossierPublic) =>
    readdirSync(dossierPublic, { withFileTypes: true })
        .filter((entree) => entree.isFile() && MOTIF_DES_ICONES.test(entree.name))
        .map((entree) => entree.name)
        .sort()
        .map((nom) => ({
            url: `/${nom}`,
            revision: createHash('sha256')
                .update(readFileSync(join(dossierPublic, nom)))
                .digest('hex'),
        }))

import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'

import { elementsDuGabarit, gabarit } from './gabarits'
import { collectSourceFiles, jsRoot } from './sourceFiles'

/**
 * Une commande ne se pose jamais dans une autre (#2117).
 *
 * Six commandes de navigation s'écrivaient `<Link><GlassButton>…</GlassButton></Link>` :
 * le `Link` d'Inertia rend un `<a href>`, `GlassButton` un `<button>`, et le
 * HTML interdit ce contenu interactif imbriqué (règle axe « nested-interactive »).
 * Au clavier, Tab s'arrêtait deux fois pour le même geste ; un lecteur d'écran
 * annonçait « lien Nouveau modèle » puis « bouton Nouveau modèle ».
 * `ExerciseCard` gardait déjà ses boutons hors de son lien pour cette raison.
 *
 * Une navigation qui doit ressembler à un bouton passe `href` à `GlassButton`,
 * qui se rend alors par `Link`, un seul `<a>` aux classes du bouton.
 *
 * La garde lit chaque gabarit balise par balise (`gabarits.js`) et refuse un
 * lien d'Inertia, un lien HTML, un bouton brut, un `GlassButton` ou un
 * `GlassIconButton` posé, à quelque profondeur que ce soit, dans l'un d'eux.
 * Ce qu'elle ne voit pas : une commande rendue par un composant qu'elle ne
 * connaît pas (`NavLink`, `DropdownLink`, `GlassChip`…), ou glissée dans le
 * créneau d'un composant qui la pose lui-même dans une commande.
 */
const COMMANDES = new Set(['Link', 'a', 'button', 'GlassButton', 'GlassIconButton'])

/**
 * Les commandes d'un composant monofichier posées dans une autre commande.
 *
 * @param {string} source
 * @returns {string[]} « <enfant> dans <parent> », dans l'ordre du gabarit.
 */
export const commandesImbriquees = (source) =>
    elementsDuGabarit(gabarit(source))
        .elements.filter(({ nom }) => COMMANDES.has(nom))
        .flatMap((commande) => {
            for (let parent = commande.parent; parent !== null; parent = parent.parent) {
                if (COMMANDES.has(parent.nom)) {
                    return [`<${commande.nom}> dans <${parent.nom}>`]
                }
            }

            return []
        })

describe('aucune commande dans une autre', () => {
    it('ne pose ni bouton ni lien dans un lien ou un bouton', () => {
        const fautives = collectSourceFiles().flatMap((chemin) =>
            commandesImbriquees(readFileSync(chemin, 'utf8')).map(
                (imbrication) => `${chemin.replace(jsRoot, 'resources/js')} : ${imbrication}`,
            ),
        )

        expect(fautives, 'une navigation qui ressemble à un bouton : <GlassButton :href="…">').toEqual([])
    })

    it('ne laisse passer qu’une commande seule', () => {
        const gabaritDe = (corps) => `<script setup></script>\n<template>\n<div>${corps}</div>\n</template>`

        // Les défauts de #2117.
        expect(
            commandesImbriquees(
                gabaritDe(
                    '<Link :href="route(\'templates.create\')"><GlassButton variant="primary">Nouveau modèle</GlassButton></Link>',
                ),
            ),
        ).toEqual(['<GlassButton> dans <Link>'])
        expect(
            commandesImbriquees(gabaritDe('<a href="/"><div><button type="button">Ouvrir</button></div></a>')),
        ).toEqual(['<button> dans <a>'])
        expect(
            commandesImbriquees(gabaritDe('<Link href="/"><GlassIconButton icon="edit" label="Modifier" /></Link>')),
        ).toEqual(['<GlassIconButton> dans <Link>'])
        expect(commandesImbriquees(gabaritDe('<button type="button"><Link href="/">Voir</Link></button>'))).toEqual([
            '<Link> dans <button>',
        ])

        expect(
            commandesImbriquees(
                gabaritDe('<GlassButton :href="route(\'templates.create\')">Nouveau modèle</GlassButton>'),
            ),
        ).toEqual([])
        expect(
            commandesImbriquees(
                gabaritDe('<Link href="/"><span>Voir</span></Link><button type="button">Supprimer</button>'),
            ),
        ).toEqual([])
    })
})

import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'

import { elementsDuGabarit, gabarit } from './gabarits'
import { collectSourceFiles, jsRoot, visibleText } from './sourceFiles'

/**
 * Une commande réduite à une icône se nomme (#2115).
 *
 * Sans `label`, `GlassIcon` rend `aria-hidden="true"` : un lien ou un bouton
 * qui ne contient qu'elle n'a rien à annoncer. Un lecteur d'écran dit « lien »
 * sans nom, ou lit l'adresse, et la liste des liens de la page en compte un
 * vide (WCAG 2.4.4 et 4.1.2, niveau A). C'était le cas du retour de l'en-tête
 * de bureau du jeûne, alors que ses deux jumeaux, sur les outils et sur un
 * exercice, se nommaient « Retour au profil » et « Retour aux exercices ».
 * `champsNommes.test.js` ne lit que les champs, et `touchTargets.test.js` ne
 * mesure qu'une taille : rien ne le voyait.
 *
 * La garde lit chaque lien d'Inertia (`Link`), chaque lien HTML, chaque bouton
 * brut et chaque `GlassButton` dont le contenu se réduit à une icône
 * (`GlassIcon`, une ligature Material Symbols ou un `<svg>`, au plus le nom
 * d'une ligature comme texte visible). Ce qui la nomme :
 * - `aria-label` ou `aria-labelledby`, écrit ou lié, non vide ;
 * - une icône qui porte elle-même un nom (`GlassIcon` avec `label`, rendue
 *   `role="img"`), ou un descendant nommé par `aria-label`, que le calcul du
 *   nom lit en parcourant le contenu ;
 * - un texte que la garde voit : `visibleText` garde un texte `sr-only` et
 *   écarte ce qui est `aria-hidden`.
 * Une commande dont le contenu vient d'un `<slot>` est nommée par son appel :
 * c'est un composant primitif, dont la garde lit les appels.
 *
 * Ce qu'elle ne voit pas : un nom calculé dans le script et lié par une
 * variable qui vaudrait une chaîne vide, une icône posée par `v-html`, une
 * commande rendue par un composant qu'elle ne connaît pas (`NavLink`,
 * `DropdownLink` reçoivent leur texte en créneau).
 */
const COMMANDES = new Set(['Link', 'a', 'button', 'GlassButton'])

const ICONE = /<GlassIcon\b|material-symbols|<svg\b/

const ATTRIBUT_QUI_NOMME = /(?:^|\s)(?::|v-bind:)?aria-label(?:ledby)?\s*=\s*(?:"[^"]*\S[^"]*"|'[^']*\S[^']*')/

const ICONE_NOMMEE = /<GlassIcon\b(?:[^>"']|"[^"]*"|'[^']*')*?\s(?::|v-bind:)?label\s*=\s*"[^"]*\S[^"]*"/

/**
 * Les commandes d'un composant monofichier réduites à une icône, sans nom.
 *
 * @param {string} source
 * @returns {string[]} La balise ouvrante de chaque commande sans nom, tronquée.
 */
export const commandesSansNom = (source) =>
    elementsDuGabarit(gabarit(source))
        .elements.filter(({ nom, contenu }) => COMMANDES.has(nom) && contenu !== null && ICONE.test(contenu))
        .filter(({ contenu }) => !/<slot\b/.test(contenu))
        .filter(({ contenu }) => /^[a-z_]*$/.test(visibleText(contenu)))
        .filter(
            ({ attributs, contenu }) =>
                !ATTRIBUT_QUI_NOMME.test(attributs) && !ATTRIBUT_QUI_NOMME.test(contenu) && !ICONE_NOMMEE.test(contenu),
        )
        .map(({ nom, attributs }) => `<${nom}${attributs.replace(/\s+/g, ' ')}>`.slice(0, 140))

describe('chaque commande réduite à une icône a un nom', () => {
    it('nomme chaque lien et chaque bouton réduit à une icône', () => {
        const fautives = collectSourceFiles().flatMap((chemin) =>
            commandesSansNom(readFileSync(chemin, 'utf8')).map(
                (balise) => `${chemin.replace(jsRoot, 'resources/js')} : ${balise}`,
            ),
        )

        expect(
            fautives,
            'donner un aria-label qui dit où mène le lien ou ce que fait le bouton (« Retour aux outils »)',
        ).toEqual([])
    })

    it('ne laisse passer qu’une commande nommée', () => {
        const gabaritDe = (corps) => `<script setup></script>\n<template>\n<div>${corps}</div>\n</template>`

        // Le défaut de #2115 : un lien d'Inertia qui ne contient qu'une icône décorative.
        expect(
            commandesSansNom(gabaritDe('<Link :href="route(\'tools.index\')"><GlassIcon name="arrow_back" /></Link>')),
        ).toHaveLength(1)
        expect(
            commandesSansNom(gabaritDe('<a href="/aide"><svg class="size-6"><path d="M0 0" /></svg></a>')),
        ).toHaveLength(1)
        expect(
            commandesSansNom(
                gabaritDe('<button type="button"><span class="material-symbols-outlined">close</span></button>'),
            ),
        ).toHaveLength(1)
        expect(
            commandesSansNom(gabaritDe('<GlassButton variant="primary"><GlassIcon name="add" /></GlassButton>')),
        ).toHaveLength(1)
        // Un nom vide, ou posé là où il n'atteint pas la commande, ne nomme rien.
        expect(
            commandesSansNom(gabaritDe('<Link href="/" aria-label=" "><GlassIcon name="home" /></Link>')),
        ).toHaveLength(1)
        expect(
            commandesSansNom(gabaritDe('<Link href="/"><GlassIcon name="home" title="Accueil" /></Link>')),
        ).toHaveLength(1)
        expect(
            commandesSansNom(gabaritDe('<Link href="/" title="Accueil"><GlassIcon name="home" /></Link>')),
        ).toHaveLength(1)

        expect(
            commandesSansNom(
                gabaritDe('<Link href="/" aria-label="Retour aux outils"><GlassIcon name="arrow_back" /></Link>'),
            ),
        ).toEqual([])
        expect(
            commandesSansNom(
                gabaritDe('<Link href="/" :aria-label="`Modifier ${nom}`"><GlassIcon name="edit" /></Link>'),
            ),
        ).toEqual([])
        expect(
            commandesSansNom(gabaritDe('<a href="#" aria-labelledby="titre"><GlassIcon name="info" /></a>')),
        ).toEqual([])
        expect(commandesSansNom(gabaritDe('<Link href="/"><GlassIcon name="home" label="Accueil" /></Link>'))).toEqual(
            [],
        )
        expect(
            commandesSansNom(
                gabaritDe('<Link href="/"><GlassIcon name="home" /><span class="sr-only">Accueil</span></Link>'),
            ),
        ).toEqual([])
        expect(commandesSansNom(gabaritDe('<Link href="/"><GlassIcon name="arrow_back" /> Retour</Link>'))).toEqual([])
        expect(commandesSansNom(gabaritDe('<Link href="/"><GlassIcon name="person" />{{ nom }}</Link>'))).toEqual([])
        // Un primitif reçoit son texte en créneau : ses appels sont lus à sa place.
        expect(commandesSansNom(gabaritDe('<button type="button"><GlassIcon name="add" /><slot /></button>'))).toEqual(
            [],
        )
        // Une commande qui ne contient pas d'icône relève d'autres gardes.
        expect(commandesSansNom(gabaritDe('<button type="button"></button>'))).toEqual([])
    })
})

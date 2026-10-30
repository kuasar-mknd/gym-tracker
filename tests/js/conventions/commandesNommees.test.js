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
 * Ce qui ne s'affiche que sous condition ne nomme rien quand elle est fausse :
 * le lien Notifications du layout ne contient, sans notification non lue,
 * que son icône, sa pastille (`v-if="… > 0"`) n'étant pas rendue. La garde
 * retire donc du contenu un élément sous `v-show`, et une suite `v-if`…
 * `v-else-if` qui ne finit pas par `v-else` ou dont une branche ne nomme pas
 * (`contenuToujoursRendu`).
 * Une commande dont le contenu vient d'un `<slot>` est nommée par son appel :
 * c'est un composant primitif, dont la garde lit les appels.
 *
 * Ce qu'elle ne voit pas : un nom calculé dans le script et lié par une
 * variable qui vaudrait une chaîne vide, une interpolation (`{{ nom }}`) qui
 * le vaudrait aussi, une icône posée par `v-html`, une commande rendue par un
 * composant qu'elle ne connaît pas (`NavLink`, `DropdownLink` reçoivent leur
 * texte en créneau).
 */
const COMMANDES = new Set(['Link', 'a', 'button', 'GlassButton'])

const ICONE = /<GlassIcon\b|material-symbols|<svg\b/

const ATTRIBUT_QUI_NOMME = /(?:^|\s)(?::|v-bind:)?aria-label(?:ledby)?\s*=\s*(?:"[^"]*\S[^"]*"|'[^']*\S[^']*')/

const ICONE_NOMMEE = /<GlassIcon\b(?:[^>"']|"[^"]*"|'[^']*')*?\s(?::|v-bind:)?label\s*=\s*"[^"]*\S[^"]*"/

/** La directive qui conditionne un élément : `v-if`, `v-else-if`, `v-else` ou `v-show`. */
const CONDITION = /(?:^|\s)(v-if|v-else-if|v-else|v-show)(?=[\s=/]|$)/

const conditionDe = (element) => CONDITION.exec(element.attributs)?.[1] ?? null

/**
 * Le contenu d'une commande sans ce qui peut ne pas s'afficher : un élément
 * sous `v-show`, et une suite `v-if`, `v-else-if`… qui ne finit pas par
 * `v-else`, ou dont une branche ne nomme pas. Une suite complète dont chaque
 * branche nomme reste : l'une d'elles s'affiche toujours.
 *
 * @param {string} contenu
 * @returns {string}
 */
const contenuToujoursRendu = (contenu) => {
    const { elements } = elementsDuGabarit(contenu)
    const retires = []

    elements.forEach((element) => {
        const condition = conditionDe(element)

        if (condition === 'v-show') {
            retires.push(element)
        }

        if (condition !== 'v-if') {
            return
        }

        const freres = elements.filter((autre) => autre.parent === element.parent)
        const suite = [element]

        for (let rang = freres.indexOf(element) + 1; rang < freres.length; rang += 1) {
            const suivante = conditionDe(freres[rang])

            if (suivante !== 'v-else-if' && suivante !== 'v-else') {
                break
            }

            suite.push(freres[rang])

            if (suivante === 'v-else') {
                break
            }
        }

        const complete = conditionDe(suite.at(-1)) === 'v-else'

        if (!complete || !suite.every(brancheNommee)) {
            retires.push(...suite)
        }
    })

    let garde = ''
    let position = 0

    for (const { debut, fin } of retires.sort((a, b) => a.debut - b.debut)) {
        if (debut >= position) {
            garde += contenu.slice(position, debut)
            position = fin ?? contenu.length
        }
    }

    return garde + contenu.slice(position)
}

/**
 * Un contenu nomme-t-il sa commande ? Un texte autre qu'une ligature, un
 * descendant nommé par `aria-label` ou une icône nommée, parmi ce qui
 * s'affiche toujours.
 *
 * @param {string} contenu
 * @returns {boolean}
 */
const contenuQuiNomme = (contenu) => {
    const toujours = contenuToujoursRendu(contenu)

    return !/^[a-z_]*$/.test(visibleText(toujours)) || ATTRIBUT_QUI_NOMME.test(toujours) || ICONE_NOMMEE.test(toujours)
}

/** Une branche d'une suite `v-if` nomme-t-elle, par elle-même ou par son contenu ? */
const brancheNommee = (branche) =>
    ATTRIBUT_QUI_NOMME.test(branche.attributs) ||
    ICONE_NOMMEE.test(`<${branche.nom}${branche.attributs}>`) ||
    contenuQuiNomme(branche.contenu ?? '')

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
        .filter(({ attributs, contenu }) => !ATTRIBUT_QUI_NOMME.test(attributs) && !contenuQuiNomme(contenu))
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
        // Un texte sous condition ne nomme rien quand la condition est fausse : le
        // lien Notifications sans notification non lue n'a plus que son icône.
        expect(
            commandesSansNom(
                gabaritDe('<Link href="/"><GlassIcon name="notifications" /><span v-if="n > 0">{{ n }}</span></Link>'),
            ),
        ).toHaveLength(1)
        expect(
            commandesSansNom(
                gabaritDe(
                    '<Link href="/"><GlassIcon name="notifications" /><span v-if="n > 0" class="pastille"></span></Link>',
                ),
            ),
        ).toHaveLength(1)
        expect(
            commandesSansNom(
                gabaritDe('<Link href="/"><GlassIcon name="home" /><span v-show="ouvert">Accueil</span></Link>'),
            ),
        ).toHaveLength(1)
        expect(
            commandesSansNom(
                gabaritDe(
                    '<button type="button"><GlassIcon name="save" /><span v-if="a">Enregistrement</span><span v-else-if="b">Enregistrer</span></button>',
                ),
            ),
        ).toHaveLength(1)
        expect(
            commandesSansNom(
                gabaritDe(
                    '<button type="button"><GlassIcon name="save" /><span v-if="a">Enregistrer</span><span v-else class="vide"></span></button>',
                ),
            ),
        ).toHaveLength(1)
        // Un `>` dans la valeur d'un attribut ne coupe pas la balise : le reste n'est pas un texte.
        expect(
            commandesSansNom(
                gabaritDe(
                    '<Link href="/"><span :class="a > b ? \'p\' : \'q\'"><GlassIcon name="home" /></span></Link>',
                ),
            ),
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
        // Une condition complète, dont chaque branche porte un texte, nomme toujours.
        expect(
            commandesSansNom(
                gabaritDe(
                    '<button type="button"><GlassIcon name="save" /><span v-if="a">Enregistrement</span><span v-else-if="b">Envoi</span><span v-else>Enregistrer</span></button>',
                ),
            ),
        ).toEqual([])
        expect(
            commandesSansNom(
                gabaritDe(
                    '<Link href="/" :aria-label="n > 0 ? `${n} non lues` : \'Notifications\'"><GlassIcon name="notifications" /><span v-if="n > 0">{{ n }}</span></Link>',
                ),
            ),
        ).toEqual([])
        expect(
            commandesSansNom(
                gabaritDe(
                    '<Link href="/"><GlassIcon name="home" /><span v-if="a" class="sr-only">Accueil</span><span v-else aria-label="Accueil" /></Link>',
                ),
            ),
        ).toEqual([])
        // Un primitif reçoit son texte en créneau : ses appels sont lus à sa place.
        expect(commandesSansNom(gabaritDe('<button type="button"><GlassIcon name="add" /><slot /></button>'))).toEqual(
            [],
        )
        // Une commande qui ne contient pas d'icône relève d'autres gardes.
        expect(commandesSansNom(gabaritDe('<button type="button"></button>'))).toEqual([])
    })
})

import { it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { collectSourceFiles, jsRoot } from './sourceFiles'
import { motsQuiVouvoient } from './vouvoiement'

/**
 * L'application tutoie (#1980, et le docbloc de `ConfirmDialog.vue`). #1980
 * n'avait passé au tutoiement que la section des notifications du profil, et
 * sa garde (`components/notificationPreferences.test.js`) ne lisait qu'elle :
 * ailleurs, des écrans voisins changeaient de registre. La page des modèles
 * disait « Crée tes routines pour gagner du temps. », celle des compléments
 * « Ajoutez vos compléments… », le journal « Votre journal est vide », et le
 * modèle qu'une séance en cours empêchait de démarrer « Terminez-la avant
 * d'en commencer une autre » (#2119).
 *
 * La garde lit chaque fichier de resources/js, commentaires compris : un
 * exemple de docbloc (`<GlassSection description="…">`) se recopie dans un
 * gabarit. Elle lit chaque ligne telle qu'elle s'affiche, échappements
 * remplacés par ce qu'ils écrivent (`\n`, `\t`, `\x…`, `\u…`). Aucun fichier
 * n'en est exempté : rien de resources/js ne s'adresse à quelqu'un d'autre
 * que la personne qui s'entraîne (le panneau d'administration, qui vouvoie
 * comme Filament, est rendu par le serveur, et sa garde est
 * `tests/Feature/Conventions/LesTextesDuServeurTutoientTest.php`).
 */

/**
 * Un échappement d'une chaîne JavaScript. `\\` en est un, qui écrit un
 * antislash : dans `'C:\\nVotre'`, le « n » n'est pas échappé.
 */
const ECHAPPEMENT = /\\(?:u\{([0-9a-fA-F]+)\}|u([0-9a-fA-F]{4})|x([0-9a-fA-F]{2})|([nrtvfb0\\]))/g
const ECRIT_PAR = { n: '\n', r: '\r', t: '\t', v: '\v', f: '\f', b: '\b', 0: '\0', '\\': '\\' }

/**
 * Ce qu'une ligne du source affiche : chaque échappement remplacé par ce
 * qu'il écrit. Lue telle quelle, « '…\nVotre…' » collait le « n » de
 * l'échappement au mot, et le motif ne le reconnaissait plus.
 *
 * @param {string} ligne
 * @returns {string}
 */
const sansEchappements = (ligne) =>
    ligne.replace(ECHAPPEMENT, (_, accolades, quatreChiffres, deuxChiffres, lettre) => {
        if (lettre !== undefined) {
            return ECRIT_PAR[lettre]
        }

        const point = Number.parseInt(accolades ?? quatreChiffres ?? deuxChiffres, 16)

        return point <= 0x10ffff ? String.fromCodePoint(point) : ' '
    })

/**
 * Les mots qui vouvoient dans le texte qu'une ligne du source affiche.
 *
 * @param {string} ligne
 * @returns {string[]}
 */
const motsDeLaLigne = (ligne) => motsQuiVouvoient(sansEchappements(ligne))

it('tutoie dans chaque texte de resources/js', () => {
    const fichiers = collectSourceFiles({ extensions: ['.vue', '.js'] })

    // Sans fichier lu, la garde passerait sans rien vérifier.
    expect(fichiers.length).toBeGreaterThan(200)

    const fautifs = fichiers.flatMap((fichier) =>
        readFileSync(fichier, 'utf8')
            .split('\n')
            .flatMap((ligne, rang) => {
                const mots = motsDeLaLigne(ligne)

                return mots.length === 0
                    ? []
                    : [`${fichier.replace(jsRoot, 'resources/js')}:${rang + 1} ${mots.join(', ')}`]
            }),
    )

    expect(fautifs, 'tutoyer, comme le reste de l’application (#1980)').toEqual([])
})

it('reconnaît le vouvoiement qu’elle refuse, et laisse passer le tutoiement', () => {
    expect(motsQuiVouvoient('Votre journal est vide')).toEqual(['Votre'])
    expect(motsQuiVouvoient("Commencez par ajouter une note pour aujourd'hui.")).toEqual(['Commencez'])
    expect(motsQuiVouvoient('Créez et lancez vos propres minuteurs')).toEqual(['Créez', 'lancez', 'vos'])
    expect(motsQuiVouvoient("une séance est déjà en cours. Terminez-la avant d'en commencer une autre.")).toEqual([
        'Terminez',
    ])
    expect(motsQuiVouvoient('Êtes-vous sûr ? Veuillez réessayer. La vôtre.')).toEqual([
        'Êtes',
        'vous',
        'Veuillez',
        'vôtre',
    ])
    expect(motsQuiVouvoient('Ton journal est vide. Commence par ajouter une note.')).toEqual([])
    expect(motsQuiVouvoient('Crée et lance tes propres minuteurs, chez toi, assez vite.')).toEqual([])
    expect(motsQuiVouvoient('3 modifications faites hors ligne ; les choses dites.')).toEqual([])
    expect(motsQuiVouvoient('const vosges = rezDeChaussee + nouveauVous')).toEqual([])
})

it('lit chaque ligne telle qu’elle s’affiche, échappements compris', () => {
    expect(motsDeLaLigne(String.raw`const MESSAGE = 'Séance enregistrée.\nVotre record tient toujours.'`)).toEqual([
        'Votre',
    ])
    expect(motsDeLaLigne(String.raw`titre: "Bravo !\tContinuez ainsi."`)).toEqual(['Continuez'])
    expect(motsDeLaLigne(String.raw`'Pr\u00e9f\u{e9}rez la barre, \x76os séries.'`)).toEqual(['Préférez', 'vos'])
    // Un antislash échappé n'échappe rien : « C:\nVotre » ne dit pas « Votre ».
    expect(motsDeLaLigne(String.raw`const chemin = 'C:\\nVotre'`)).toEqual([])
})

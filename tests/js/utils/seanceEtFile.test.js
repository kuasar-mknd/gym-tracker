import { describe, it, expect } from 'vitest'
import { entreeDeLaRangee, identifiantEnFile, seanceConnueDuServeur, superposerLaFile } from '@/Utils/seanceEtFile'

/*
 * La séance rendue par le serveur, avec par-dessus ce qui attend encore dans la
 * file hors ligne (#2048). Les entrées sont écrites comme `SyncService` les range :
 * l'adresse que Ziggy a rendue, la charge, la clé d'idempotence d'une création,
 * l'adresse de rattrapage, le compte.
 */

const SQUAT = { id: 7, name: 'Squat', type: 'strength', category: 'Jambes' }
const COURSE = { id: 8, name: 'Course', type: 'cardio', category: 'Cardio' }

const seance = () => ({
    id: 5,
    name: 'Séance',
    workout_lines: [
        {
            id: 1,
            exercise_id: 7,
            exercise: SQUAT,
            sets: [
                { id: 11, weight: 80, reps: 5, is_completed: true, is_warmup: false },
                { id: 12, weight: 85, reps: 3, is_completed: false, is_warmup: false },
            ],
        },
        { id: 2, exercise_id: 8, exercise: COURSE, sets: [{ id: 21, distance_km: 5, duration_seconds: 1500 }] },
    ],
})

let numero = 0

/** Une entrée de file, telle que `SyncService.addToQueue` l'écrit. */
const entree = (method, url, data, extra = {}) => ({
    method,
    url,
    data,
    headers: method === 'post' ? { 'Idempotency-Key': `cle-${numero}` } : {},
    id: `q${++numero}`,
    timestamp: '2026-10-12T18:04:00.000Z',
    compte: '1',
    ...extra,
})

const creationDeSerie = (data, extra = {}) =>
    entree('post', '/api/v1/sets', data, { ajusterPar: '/api/v1/sets/__produit__', ...extra })

const ecran = (copie) => copie.workout_lines.map((ligne) => [ligne.id, ligne.sets.map((serie) => serie.id)])

describe('superposer la file à la séance du serveur', () => {
    it('rend une copie, lignes en tableau, sans toucher à la séance ni aux entrées', () => {
        const duServeur = { id: 5, workout_lines: { a: { id: 1, exercise: SQUAT, sets: [] } } }
        const entrees = [creationDeSerie({ workout_line_id: 1, is_completed: false, weight: 60, reps: 8 })]
        const avant = JSON.stringify([duServeur, entrees])

        const copie = superposerLaFile(duServeur, entrees)

        expect(JSON.stringify([duServeur, entrees])).toBe(avant)
        expect(copie.workout_lines).toHaveLength(1)
        expect(copie.workout_lines[0].sets).toHaveLength(1)
        expect(superposerLaFile(seance(), [])).toEqual(seance())
        expect(superposerLaFile({ id: 5 }, undefined).workout_lines).toEqual([])
    })

    it('montre une série créée hors ligne, cochée et saisie, sous son entrée de file', () => {
        const creation = creationDeSerie({ workout_line_id: 1, is_completed: true, weight: 82.5, reps: 7 })

        const copie = superposerLaFile(seance(), [creation])

        expect(ecran(copie)).toEqual([
            [1, [11, 12, `temp-q${creation.id}`]],
            [2, [21]],
        ])
        expect(copie.workout_lines[0].sets[2]).toEqual({
            id: `temp-q${creation.id}`,
            _rowKey: `temp-q${creation.id}`,
            is_completed: true,
            is_warmup: false,
            weight: 82.5,
            reps: 7,
        })
    })

    it('montre un exercice créé hors ligne, avec ses séries qui l’attendent en file', () => {
        const ligne = entree('post', 'https://example.org/api/v1/workout-lines', { workout_id: 5, exercise_id: 8 })
        const premiere = creationDeSerie({
            workout_line_id: { enAttenteDe: ligne.id },
            is_completed: false,
            distance_km: 3,
            duration_seconds: 900,
        })
        const seconde = creationDeSerie({ workout_line_id: { enAttenteDe: ligne.id }, is_completed: true })

        const copie = superposerLaFile(seance(), [ligne, premiere, seconde], { exercices: [SQUAT, COURSE] })

        expect(ecran(copie)).toEqual([
            [1, [11, 12]],
            [2, [21]],
            [`temp-q${ligne.id}`, [`temp-q${premiere.id}`, `temp-q${seconde.id}`]],
        ])
        expect(copie.workout_lines[2]).toMatchObject({
            _rowKey: `temp-q${ligne.id}`,
            exercise_id: 8,
            exercise: COURSE,
            order: 2,
            notes: null,
            recommended_values: null,
        })
        expect(copie.workout_lines[2].exercise).not.toBe(COURSE)
        expect(copie.workout_lines[2].sets[0]).toMatchObject({ distance_km: 3, duration_seconds: 900 })
    })

    it('montre un exercice que la liste ne connaît plus, sa création attendant encore', () => {
        const ligne = entree('post', '/api/v1/workout-lines', { workout_id: 5, exercise_id: 99 })

        const copie = superposerLaFile(seance(), [ligne], { exercices: [SQUAT] })

        expect(copie.workout_lines[2].exercise).toEqual({ id: 99, name: 'Exercice', type: 'strength' })
    })

    it('applique les modifications et les suppressions, rattrapages et annulations compris', () => {
        const copie = superposerLaFile(seance(), [
            entree('patch', '/api/v1/sets/11', { weight: 82.5, is_completed: false }),
            entree('put', '/api/v1/sets/21', { distance_km: 6 }),
            entree('delete', '/api/v1/sets/12'),
            entree('delete', '/api/v1/sets/999', undefined, { annulation: true }),
        ])

        expect(ecran(copie)).toEqual([
            [1, [11]],
            [2, [21]],
        ])
        expect(copie.workout_lines[0].sets[0]).toMatchObject({ id: 11, weight: 82.5, reps: 5, is_completed: false })
        expect(copie.workout_lines[1].sets[0]).toMatchObject({ distance_km: 6 })
    })

    it('retire un exercice supprimé hors ligne, et ne montre pas ce que la personne a retiré de l’écran', () => {
        const retiree = creationDeSerie(
            { workout_line_id: 1, is_completed: false, weight: 60, reps: 8 },
            { tentee: true, aAnnuler: '/api/v1/sets/__produit__' },
        )
        const ligneRetiree = entree(
            'post',
            '/api/v1/workout-lines',
            { workout_id: 5, exercise_id: 7 },
            { tentee: true, aAnnuler: '/api/v1/workout-lines/__produit__' },
        )

        const copie = superposerLaFile(seance(), [entree('delete', '/api/v1/workout-lines/2'), retiree, ligneRetiree], {
            exercices: [SQUAT],
        })

        expect(ecran(copie)).toEqual([[1, [11, 12]]])
    })

    /*
     * Une création partie sans réponse a pu atteindre le serveur : la séance
     * rechargée porte déjà ce qu'elle a produit, sous sa clé d'idempotence.
     */
    it('ne montre pas deux fois une création arrivée au serveur dont la réponse s’est perdue', () => {
        const duServeur = seance()
        duServeur.workout_lines[0].sets[1].idempotency_key = 'cle-serie-arrivee'
        duServeur.workout_lines.push({
            id: 3,
            exercise_id: 8,
            exercise: COURSE,
            sets: [],
            idempotency_key: 'cle-ligne',
        })
        duServeur.workout_lines.push({
            id: 4,
            exercise_id: 7,
            exercise: SQUAT,
            sets: [],
            idempotency_key: 'cle-retiree',
        })

        const serieArrivee = creationDeSerie(
            { workout_line_id: 1, is_completed: true, weight: 85, reps: 6 },
            { tentee: true, headers: { 'Idempotency-Key': 'cle-serie-arrivee' } },
        )
        const ligneArrivee = entree(
            'post',
            '/api/v1/workout-lines',
            { workout_id: 5, exercise_id: 8 },
            { tentee: true, headers: { 'Idempotency-Key': 'cle-ligne' } },
        )
        const sousLaLigneArrivee = creationDeSerie({
            workout_line_id: { enAttenteDe: ligneArrivee.id },
            is_completed: false,
            distance_km: 2,
        })
        const ligneRetiree = entree(
            'post',
            '/api/v1/workout-lines',
            { workout_id: 5, exercise_id: 7 },
            {
                tentee: true,
                aAnnuler: '/api/v1/workout-lines/__produit__',
                headers: { 'Idempotency-Key': 'cle-retiree' },
            },
        )

        const copie = superposerLaFile(duServeur, [serieArrivee, ligneArrivee, sousLaLigneArrivee, ligneRetiree], {
            exercices: [SQUAT, COURSE],
        })

        expect(ecran(copie)).toEqual([
            [1, [11, 12]],
            [2, [21]],
            [3, [`temp-q${sousLaLigneArrivee.id}`]],
        ])
        expect(copie.workout_lines[0].sets[1]).toMatchObject({ id: 12, weight: 85, reps: 6, is_completed: true })
    })

    it('retire une série arrivée au serveur que la personne a supprimée depuis', () => {
        const duServeur = seance()
        duServeur.workout_lines[0].sets[1].idempotency_key = 'cle-serie-retiree'

        const copie = superposerLaFile(duServeur, [
            creationDeSerie(
                { workout_line_id: 1, is_completed: false, weight: 85, reps: 3 },
                {
                    tentee: true,
                    aAnnuler: '/api/v1/sets/__produit__',
                    headers: { 'Idempotency-Key': 'cle-serie-retiree' },
                },
            ),
            // Une clé qu'aucune rangée ne porte : la création n'est pas arrivée, elle se superpose.
            creationDeSerie({ workout_line_id: 1, is_completed: false, weight: 90, reps: 1 }, { headers: {} }),
        ])

        expect(ecran(copie)[0][1]).toEqual([11, expect.stringMatching(/^temp-q/)])
    })

    it('reprend l’ordre des exercices et des séries, ceux qu’il ne nomme pas à la suite', () => {
        const nouvelle = creationDeSerie({ workout_line_id: 1, is_completed: false, weight: 90, reps: 1 })
        const ligne = entree('post', '/api/v1/workout-lines', { workout_id: 5, exercise_id: 7 })

        const copie = superposerLaFile(
            seance(),
            [
                entree('patch', '/api/v1/workout-lines/1/set-order', { sets: [12, 11] }),
                nouvelle,
                ligne,
                entree('patch', '/api/v1/workouts/5/line-order', { lines: [2, 1] }),
            ],
            { exercices: [SQUAT] },
        )

        expect(ecran(copie)).toEqual([
            [2, [21]],
            [1, [12, 11, `temp-q${nouvelle.id}`]],
            [`temp-q${ligne.id}`, []],
        ])
    })

    it('ignore ce qui concerne une autre séance, ou rien de la séance', () => {
        const copie = superposerLaFile(seance(), [
            entree('post', '/api/v1/workout-lines', { workout_id: 6, exercise_id: 7 }),
            creationDeSerie({ workout_line_id: 40, is_completed: false, weight: 50, reps: 5 }),
            creationDeSerie({ workout_line_id: { enAttenteDe: 'absente' }, is_completed: false }),
            entree('patch', '/api/v1/sets/40', { weight: 1 }),
            entree('delete', '/api/v1/workout-lines/40'),
            entree('patch', '/api/v1/workouts/6/line-order', { lines: [2, 1] }),
            entree('patch', '/api/v1/workout-lines/40/set-order', { sets: [1] }),
            entree('patch', '/profile/preferences', { notify: true }),
            entree('get', '/api/v1/sets'),
        ])

        expect(copie).toEqual(seance())
    })

    /*
     * L'entrée d'historique d'une visite plus ancienne de la même séance n'a
     * pas l'exercice ajouté en ligne depuis : la série mise en file sous lui
     * ne trouvait plus sa ligne et disparaissait avec lui, alors que la file
     * la gardait, et la personne la refaisait (#2048).
     */
    it('reconstruit la ligne réelle qu’une série en file nomme, d’après le contexte de son entrée', () => {
        const contexte = { workout_id: 5, exercise_id: 7 }
        const premiere = creationDeSerie(
            { workout_line_id: 70, is_completed: true, weight: 60, reps: 10 },
            { contexte },
        )
        const seconde = creationDeSerie({ workout_line_id: '70', is_completed: false, weight: 62.5 }, { contexte })
        const inconnue = creationDeSerie(
            { workout_line_id: 71, is_completed: false, reps: 3 },
            { contexte: { workout_id: '5', exercise_id: 99 } },
        )

        const copie = superposerLaFile(seance(), [premiere, seconde, inconnue], { exercices: [SQUAT, COURSE] })

        expect(ecran(copie)).toEqual([
            [1, [11, 12]],
            [2, [21]],
            [70, [`temp-q${premiere.id}`, `temp-q${seconde.id}`]],
            [71, [`temp-q${inconnue.id}`]],
        ])
        expect(copie.workout_lines[2]).toEqual({
            id: 70,
            exercise_id: 7,
            exercise: SQUAT,
            sets: copie.workout_lines[2].sets,
            order: 2,
            notes: null,
            recommended_values: null,
        })
        expect(copie.workout_lines[2].sets[0]).toMatchObject({ is_completed: true, weight: 60, reps: 10 })
        // Un exercice que la liste ne connaît pas reste montré : la série attend encore.
        expect(copie.workout_lines[3].exercise).toEqual({ id: 99, name: 'Exercice', type: 'strength' })
    })

    it('ne reconstruit rien pour une autre séance, sans contexte, sous une ligne encore en file, ni pour une création retirée', () => {
        const contexte = { workout_id: 5, exercise_id: 7 }
        const copie = superposerLaFile(
            seance(),
            [
                creationDeSerie(
                    { workout_line_id: 70, is_completed: false },
                    { contexte: { ...contexte, workout_id: 6 } },
                ),
                creationDeSerie({ workout_line_id: 71, is_completed: false }),
                creationDeSerie({ workout_line_id: 72, is_completed: false }, { contexte: { workout_id: 5 } }),
                creationDeSerie({ workout_line_id: { enAttenteDe: 'absente' }, is_completed: false }, { contexte }),
                creationDeSerie({ workout_line_id: 'temp-4', is_completed: false }, { contexte }),
                creationDeSerie(
                    { workout_line_id: 73, is_completed: false },
                    { contexte, tentee: true, aAnnuler: '/api/v1/sets/__produit__' },
                ),
            ],
            { exercices: [SQUAT] },
        )

        expect(copie).toEqual(seance())
    })

    it('retire la ligne reconstruite quand la file la retire ensuite', () => {
        const copie = superposerLaFile(
            seance(),
            [
                creationDeSerie(
                    { workout_line_id: 70, is_completed: true },
                    { contexte: { workout_id: 5, exercise_id: 7 } },
                ),
                entree('delete', '/api/v1/workout-lines/70'),
            ],
            { exercices: [SQUAT] },
        )

        expect(copie).toEqual(seance())
    })

    it('lit une charge déjà sérialisée, et passe sur une charge illisible', () => {
        const copie = superposerLaFile(seance(), [
            entree('patch', '/api/v1/sets/11', JSON.stringify({ weight: 70 })),
            entree('patch', '/api/v1/sets/12', '{illisible'),
        ])

        expect(copie.workout_lines[0].sets.map((serie) => serie.weight)).toEqual([70, 85])
    })
})

describe('l’identifiant d’une rangée superposée', () => {
    it('nomme l’entrée de file qui la crée, et rien d’autre', () => {
        expect(identifiantEnFile('1791833275396f3qnek8gw')).toBe('temp-q1791833275396f3qnek8gw')
        expect(entreeDeLaRangee('temp-q1791833275396f3qnek8gw')).toBe('1791833275396f3qnek8gw')

        // Une rangée ajoutée depuis le montage, une rangée du serveur, un préfixe nu.
        expect(entreeDeLaRangee('temp-3')).toBeNull()
        expect(entreeDeLaRangee(42)).toBeNull()
        expect(entreeDeLaRangee('temp-q')).toBeNull()
        expect(entreeDeLaRangee(undefined)).toBeNull()
    })
})

describe('la séance que garde l’entrée d’historique', () => {
    it('garde les rangées que le serveur connaît, avec les valeurs de l’écran, sans les rangées provisoires', () => {
        const aLEcran = seance()
        aLEcran.workout_lines[0].sets[1].weight = 87.5
        aLEcran.workout_lines[0].sets.push({ id: 'temp-2', _rowKey: 'row-2', weight: 90, reps: 1 })
        aLEcran.workout_lines[0].sets.push({ id: 'temp-qq7', _rowKey: 'temp-qq7', weight: 95, reps: 1 })
        aLEcran.workout_lines.push({ id: 'temp-1', _rowKey: 'row-1', exercise: COURSE, sets: [] })

        const gardee = seanceConnueDuServeur(aLEcran)

        expect(ecran(gardee)).toEqual([
            [1, [11, 12]],
            [2, [21]],
        ])
        expect(gardee.workout_lines[0].sets[1]).toMatchObject({ id: 12, weight: 87.5 })
    })

    /*
     * Gardée telle qu'à l'écran, une valeur refusée après le départ de la
     * page revenait au bouton Avance comme enregistrée, sans marque (#2048).
     */
    it('garde à sa valeur de repli un champ dont l’écriture attend encore, d’une série réelle seulement', () => {
        const aLEcran = seance()
        aLEcran.workout_lines[0].sets[1].weight = 99999
        aLEcran.workout_lines[0].sets[1].is_completed = true
        aLEcran.workout_lines[0].sets[0].reps = 6
        aLEcran.workout_lines[0].sets.push({ id: 'temp-qq7', _rowKey: 'temp-qq7', weight: 95, reps: 1 })

        const gardee = seanceConnueDuServeur(aLEcran, [
            { id: '12', champ: 'weight', valeur: 85 },
            { id: 12, champ: 'is_completed', valeur: false },
            { id: 'temp-qq7', champ: 'weight', valeur: 1 },
            { id: 40, champ: 'weight', valeur: 1 },
        ])

        expect(ecran(gardee)).toEqual([
            [1, [11, 12]],
            [2, [21]],
        ])
        expect(gardee.workout_lines[0].sets[1]).toMatchObject({ weight: 85, is_completed: false })
        // Une valeur sans écriture en attente reste celle de l'écran.
        expect(gardee.workout_lines[0].sets[0].reps).toBe(6)
        expect(aLEcran.workout_lines[0].sets[1]).toMatchObject({ weight: 99999, is_completed: true })
    })

    it('retire les champs de l’écran, dont la clef qu’un montage suivant redonnerait à une autre rangée', () => {
        const aLEcran = seance()
        aLEcran.workout_lines[0]._rowKey = 'row-1'
        aLEcran.workout_lines[0].sets[0]._rowKey = 'row-2'

        const gardee = seanceConnueDuServeur(aLEcran)

        expect(gardee).toEqual(seance())
    })

    it('rend une copie, lignes en tableau, sans toucher à la séance reçue', () => {
        const aLEcran = {
            id: 5,
            workout_lines: { a: { id: 1, exercise: SQUAT, sets: [{ id: 11, _rowKey: 'row-1' }] } },
        }
        const avant = JSON.stringify(aLEcran)

        const gardee = seanceConnueDuServeur(aLEcran)

        expect(JSON.stringify(aLEcran)).toBe(avant)
        expect(gardee.workout_lines).toEqual([{ id: 1, exercise: SQUAT, sets: [{ id: 11 }] }])
    })
})

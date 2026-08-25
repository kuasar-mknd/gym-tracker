<?php

declare(strict_types=1);

/*
 * `FetchHabitsIndexAction` etait execute par HabitControllerTest sans etre
 * verifie : 19 de ses 29 mutants survivaient.
 *
 * Concretement, chacune de ces reecritures passait la suite au vert :
 *
 *  - charger TOUS les suivis d'une habitude au lieu des seuls suivis de la
 *    semaine affichee (le `whereBetween` du chargement anticipe) ;
 *  - rendre 29, 31 ou zero jour de statistiques au lieu de 30 ;
 *  - lire la fenetre a l'envers, ou la decaler d'un jour dans un sens ou dans
 *    l'autre ;
 *  - mettre tous les compteurs a zero, ou rendre 1 (ou -1) les jours sans
 *    suivi ;
 *  - retirer `date` ou `count` de chaque entree du graphe en ligne, et `date`,
 *    `full_date` ou `count` de chaque entree du graphe en barres ;
 *  - retirer n'importe laquelle des six cles de chaque jour de la grille de la
 *    semaine, ou changer le nombre de jours qu'elle contient.
 *
 * Le compte reel mesure ici est 28 survivants sur 45 mutants (37,78 %), et non
 * les 19 sur 29 annonces : la grille de la semaine (`getWeekDates`) manquait a
 * la liste de depart.
 *
 * Les valeurs comparees ici sont toutes POSEES : la fabrique de `HabitLog`
 * distribue des dates a partir de 2015 et la fabrique de `Habit` tire un
 * objectif hebdomadaire au hasard, donc rien de ce qu'elles produisent ne peut
 * servir d'attendu.
 */

use App\Actions\Habits\FetchHabitsIndexAction;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Mercredi 17 juin 2026 a midi.
 *
 * Un mercredi, pour que la semaine courante (lundi 15 -> dimanche 21) ait des
 * jours des deux cotes de « aujourd'hui » ; et un mois de juin, loin des deux
 * changements d'heure : `subDays(29)` remonte jusqu'au 19 mai sans traverser
 * un jour de 23 ou 25 heures, qui rendrait `format('d/m')` faux deux fois par
 * an sans que personne comprenne pourquoi.
 */
function scenePourHabitudes(): User
{
    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00'));

    return User::factory()->create();
}

function habitudeDe(User $user, string $nom): Habit
{
    return Habit::factory()->create(['user_id' => $user->id, 'name' => $nom]);
}

function suiviHabitudeLe(Habit $habit, string $jour): void
{
    HabitLog::create(['habit_id' => $habit->id, 'date' => $jour]);
}

it('rend trente jours de statistiques, du plus ancien au plus recent', function (): void {
    $user = scenePourHabitudes();

    $stats = app(FetchHabitsIndexAction::class)->getStatsData($user);

    // Trente entrees, ni 29 ni 31 : c'est la fenetre que la page annonce, et la
    // seule assertion qui tienne les deux bornes de la boucle.
    expect($stats['consistencyData'])->toHaveCount(30);
    expect($stats['history'])->toHaveCount(30);

    // Le sens de lecture, que rien ne verifiait : la boucle descend de J-29 a
    // aujourd'hui, donc la premiere entree est la plus ancienne. Un mutant qui
    // remonte de 0 a 29 rendrait les memes trente jours a l'envers.
    expect($stats['consistencyData'][0]['date'])->toBe('2026-05-19');
    expect($stats['consistencyData'][29]['date'])->toBe('2026-06-17');

    // Le graphe en barres porte deux formes de la meme date : l'etiquette
    // courte affichee, et la date complete utilisee comme cle.
    expect($stats['history'][0]['date'])->toBe('19/05');
    expect($stats['history'][0]['full_date'])->toBe('2026-05-19');
    expect($stats['history'][29]['date'])->toBe('17/06');
    expect($stats['history'][29]['full_date'])->toBe('2026-06-17');
});

it('compte les suivis de chaque jour et laisse a zero les jours sans suivi', function (): void {
    $user = scenePourHabitudes();

    $lecture = habitudeDe($user, 'Lecture');
    $etirements = habitudeDe($user, 'Etirements');
    $meditation = habitudeDe($user, 'Meditation');

    // Un compte DIFFERENT par jour : avec le meme compte partout, un decalage
    // d'indice d'un cran ne se verrait sur aucune assertion.
    suiviHabitudeLe($lecture, '2026-06-17');      // J-0 : trois habitudes
    suiviHabitudeLe($etirements, '2026-06-17');
    suiviHabitudeLe($meditation, '2026-06-17');

    suiviHabitudeLe($lecture, '2026-06-16');      // J-1 : deux
    suiviHabitudeLe($etirements, '2026-06-16');

    suiviHabitudeLe($lecture, '2026-06-15');      // J-2 : une

    // J-29, la borne basse de la fenetre : elle doit etre DEDANS.
    suiviHabitudeLe($lecture, '2026-05-19');
    suiviHabitudeLe($etirements, '2026-05-19');

    // J-30, juste dehors. Sa presence prouve que la requete n'a pas besoin
    // d'aller le chercher — et qu'aller le chercher ne change rien.
    suiviHabitudeLe($lecture, '2026-05-18');

    // Un voisin qui suit ses propres habitudes le meme jour : sans le filtre
    // sur `habits.user_id`, ses suivis gonfleraient le compte du 16.
    $voisin = User::factory()->create();
    suiviHabitudeLe(habitudeDe($voisin, 'Course'), '2026-06-16');

    $stats = app(FetchHabitsIndexAction::class)->getStatsData($user);

    // Trois, deux, un : un `?? 0` transforme en `?? 1`, ou un `count` ecrase
    // par la partie droite du coalesce, rendrait ces trois jours identiques.
    expect($stats['consistencyData'][29]['count'])->toBe(3);
    expect($stats['consistencyData'][28]['count'])->toBe(2);
    expect($stats['consistencyData'][27]['count'])->toBe(1);

    // Un jour sans aucun suivi vaut zero, pas un ni moins un.
    expect($stats['consistencyData'][26]['count'])->toBe(0);

    // La borne basse : un jour de moins dans `subDays()` la ferait tomber
    // dehors et ce 2 deviendrait 0.
    expect($stats['consistencyData'][0]['date'])->toBe('2026-05-19');
    expect($stats['consistencyData'][0]['count'])->toBe(2);

    // Le graphe en barres porte les memes comptes que le graphe en ligne.
    expect($stats['history'][29]['count'])->toBe(3);
    expect($stats['history'][28]['count'])->toBe(2);
    expect($stats['history'][27]['count'])->toBe(1);
    expect($stats['history'][26]['count'])->toBe(0);
    expect($stats['history'][0]['count'])->toBe(2);
});

it('nomme exactement les cles de chacune des trente entrees', function (): void {
    $user = scenePourHabitudes();
    suiviHabitudeLe(habitudeDe($user, 'Lecture'), '2026-06-17');

    $stats = app(FetchHabitsIndexAction::class)->getStatsData($user);

    // Sur les TRENTE entrees et pas sur un echantillon : le mutant retire la
    // cle du litteral, donc de toutes les entrees a la fois, mais un jour ou
    // le tableau serait construit autrement, un echantillon mentirait.
    expect(array_map(array_keys(...), $stats['consistencyData']))
        ->toBe(array_fill(0, 30, ['date', 'count']));

    expect(array_map(array_keys(...), $stats['history']))
        ->toBe(array_fill(0, 30, ['date', 'full_date', 'count']));
});

it('ne charge que les suivis de la semaine affichee', function (): void {
    $user = scenePourHabitudes();
    $lecture = habitudeDe($user, 'Lecture');

    suiviHabitudeLe($lecture, '2026-06-14'); // dimanche precedent : dehors
    suiviHabitudeLe($lecture, '2026-06-15'); // lundi : borne basse, dedans
    suiviHabitudeLe($lecture, '2026-06-17'); // mercredi : dedans
    suiviHabitudeLe($lecture, '2026-06-21'); // dimanche : borne haute, dedans
    suiviHabitudeLe($lecture, '2026-06-22'); // lundi suivant : dehors

    // Une habitude archivee n'a plus a paraitre sur la page.
    Habit::factory()->create([
        'user_id' => $user->id,
        'name' => 'Ancienne',
        'archived' => true,
    ]);

    $donnees = app(FetchHabitsIndexAction::class)->getImmediateData($user);

    // La semaine que la page dessine, et donc la fenetre que le chargement
    // anticipe doit respecter.
    expect($donnees['weekDates'][0]['date'])->toBe('2026-06-15');
    expect($donnees['weekDates'][6]['date'])->toBe('2026-06-21');

    // L'habitude archivee n'est pas la : une seule habitude rendue.
    expect($donnees['habits'])->toHaveCount(1);
    $habitude = $donnees['habits']->firstOrFail();

    expect($habitude->relationLoaded('logs'))->toBeTrue();

    // Trois suivis sur cinq. Sans le `whereBetween`, la relation en rendrait
    // cinq : la page afficherait juste, mais chaque habitude trainerait tout
    // son historique dans la reponse.
    $joursCharges = $habitude->logs
        ->map(fn (HabitLog $suivi): string => $suivi->date->toDateString())
        ->sort()
        ->values()
        ->all();

    expect($joursCharges)->toBe(['2026-06-15', '2026-06-17', '2026-06-21']);
});

it('rend la grille de la semaine, du lundi au dimanche, avec ses six cles', function (): void {
    $user = scenePourHabitudes();

    $semaine = app(FetchHabitsIndexAction::class)->getImmediateData($user)['weekDates'];

    /*
     * Les sept jours en entier, compares a un litteral.
     *
     * C'est la seule forme d'assertion qui tienne a la fois les six cles de
     * chaque entree — un `RemoveArrayItem` en retire une pour les sept jours a
     * la fois — la longueur de la boucle, et son sens. Et chaque jour porte des
     * valeurs DIFFERENTES : avec sept entrees interchangeables, un decalage
     * d'indice ne se verrait nulle part.
     */
    expect($semaine)->toBe([
        ['date' => '2026-06-15', 'day' => 'Mon', 'day_name' => 'lundi', 'day_short' => 'lun.', 'day_num' => 15, 'is_today' => false],
        ['date' => '2026-06-16', 'day' => 'Tue', 'day_name' => 'mardi', 'day_short' => 'mar.', 'day_num' => 16, 'is_today' => false],
        ['date' => '2026-06-17', 'day' => 'Wed', 'day_name' => 'mercredi', 'day_short' => 'mer.', 'day_num' => 17, 'is_today' => true],
        ['date' => '2026-06-18', 'day' => 'Thu', 'day_name' => 'jeudi', 'day_short' => 'jeu.', 'day_num' => 18, 'is_today' => false],
        ['date' => '2026-06-19', 'day' => 'Fri', 'day_name' => 'vendredi', 'day_short' => 'ven.', 'day_num' => 19, 'is_today' => false],
        ['date' => '2026-06-20', 'day' => 'Sat', 'day_name' => 'samedi', 'day_short' => 'sam.', 'day_num' => 20, 'is_today' => false],
        ['date' => '2026-06-21', 'day' => 'Sun', 'day_name' => 'dimanche', 'day_short' => 'dim.', 'day_num' => 21, 'is_today' => false],
    ]);
});

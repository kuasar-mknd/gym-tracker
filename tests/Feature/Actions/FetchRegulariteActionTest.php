<?php

declare(strict_types=1);

use App\Actions\Stats\FetchRegulariteAction;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Carbon;

/** Une séance à une date posée, sans les écouteurs d'une séance : ce que ces tests lisent n'en dépend pas. */
function seancePourRegularite(User $user, string $quand, string $nom = 'Seance', bool $faite = false): Workout
{
    $fabrique = $faite ? Workout::factory()->faite() : Workout::factory();

    return Workout::withoutEvents(fn (): Workout => $fabrique->create([
        'user_id' => $user->id,
        'name' => $nom,
        'started_at' => Carbon::parse($quand),
        'ended_at' => Carbon::parse($quand)->addHour(),
    ]));
}

it('rend six mois de frequence, du plus ancien au plus recent, et zero sur un mois creux', function (): void {
    // Le 15 juin : loin d'un changement d'heure, et le 15 de chaque mois existe
    // partout, donc `subMonths()` ne deborde sur aucun mois court.
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $user = User::factory()->create();

    seancePourRegularite($user, '2026-01-20 10:00:00', 'Seance', true);  // janvier : le mois le plus ancien de la fenetre
    seancePourRegularite($user, '2026-06-02 10:00:00', 'Seance', true);  // juin
    seancePourRegularite($user, '2026-06-10 10:00:00', 'Seance', true);  // juin
    seancePourRegularite($user, '2025-12-20 10:00:00', 'Seance', true);  // decembre : hors fenetre, ne doit rien peser

    $frequence = app(FetchRegulariteAction::class)->execute($user)['monthly_frequency'];

    // Six mois, ni cinq ni sept : c'est la fenetre que la page annonce, et la
    // seule assertion qui tienne les deux bornes du `range(0, 5)`.
    expect($frequence)->toHaveCount(6);

    // Le sens de lecture et le calage du mois, que rien ne verifiait. Janvier
    // en tete tient a la fois la borne SQL (`startOfMonth()->subMonths(5)`, qui
    // decide si la seance du 20 janvier entre) et le decalage de la boucle : un
    // mois de plus, un mois de moins, ou une lecture a l'envers, et cette entree
    // ne s'appelle plus « janv. ».
    expect($frequence[0])->toBe(['month' => 'janv.', 'count' => 1]);
    expect($frequence[5])->toBe(['month' => 'juin', 'count' => 2]);

    // Deux seances en juin se comptent, elles ne s'ecrasent pas.
    // Et fevrier vaut exactement 0 — pas 1, pas -1 : le graphe trace ce chiffre.
    expect($frequence[1])->toBe(['month' => 'févr.', 'count' => 0]);

    // La forme de CHAQUE entree, pas d'un echantillon : une boucle qui ne
    // remplirait qu'une cle sur deux passerait un test qui ne regarde que la
    // premiere.
    foreach ($frequence as $entree) {
        expect(array_keys($entree))->toBe(['month', 'count']);
    }
});

it('associe chaque jour de la semaine a son propre compte', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $user = User::factory()->create();

    // Un compte DIFFERENT par jour. Avec le meme compte partout — ou avec un
    // seul jour rempli — decaler une cle du tableau `$jours` ne se verrait pas :
    // toutes les barres se ressembleraient. Ici, chaque jour porte sa signature.
    $seancesParJour = [
        '2026-06-08' => 1, // lundi
        '2026-06-09' => 2, // mardi
        '2026-06-10' => 3, // mercredi
        '2026-06-11' => 4, // jeudi
        '2026-06-12' => 5, // vendredi
        '2026-06-13' => 6, // samedi
        '2026-06-14' => 7, // dimanche
    ];

    foreach ($seancesParJour as $jour => $combien) {
        for ($i = 0; $i < $combien; $i++) {
            seancePourRegularite($user, $jour.' 10:00:00', 'Seance', true);
        }
    }

    $frequence = app(FetchRegulariteAction::class)->execute($user)['day_of_week_frequency'];

    // Le tableau entier, dans l'ordre, avec ses deux cles et ses comptes.
    // `toBe()` compare a l'identique : cette ligne seule tient les sept cles de
    // `$jours` (DAYOFWEEK vaut 1 le dimanche et 7 le samedi, donc la semaine
    // commence a 2), l'ordre des barres, la presence de `day` et de `count`,
    // et le fait qu'aucun compte ne parte chez le voisin.
    expect($frequence->all())->toBe([
        ['day' => 'Lun', 'count' => 1],
        ['day' => 'Mar', 'count' => 2],
        ['day' => 'Mer', 'count' => 3],
        ['day' => 'Jeu', 'count' => 4],
        ['day' => 'Ven', 'count' => 5],
        ['day' => 'Sam', 'count' => 6],
        ['day' => 'Dim', 'count' => 7],
    ]);
});

it('met zero, et non un ni moins un, sur un jour sans seance', function (): void {
    // Le test precedent remplit les sept jours, donc le repli du ternaire ne s'y
    // execute jamais. Sans utilisateur vide, « 1 » ou « -1 » a la place de « 0 »
    // passait : un graphe qui affiche une barre la ou il n'y a rien.
    $user = User::factory()->create();

    $frequence = app(FetchRegulariteAction::class)->execute($user)['day_of_week_frequency'];

    expect($frequence->all())->toBe([
        ['day' => 'Lun', 'count' => 0],
        ['day' => 'Mar', 'count' => 0],
        ['day' => 'Mer', 'count' => 0],
        ['day' => 'Jeu', 'count' => 0],
        ['day' => 'Ven', 'count' => 0],
        ['day' => 'Sam', 'count' => 0],
        ['day' => 'Dim', 'count' => 0],
    ]);
});

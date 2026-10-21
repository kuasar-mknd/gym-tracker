<?php

declare(strict_types=1);

use App\Actions\Workouts\CreateSetAction;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use Illuminate\Support\Carbon;

/*
 * Les instants que l'appareil envoie avec une séance démarrée ou terminée
 * sans réseau (#2049), et les clés qu'un lancement de modèle pose.
 *
 * La file rejoue un démarrage ou une fin des heures après le geste : le
 * serveur prend l'heure du geste, par son âge au départ de la requête, lu sur
 * l'horloge de l'appareil (relu le 2026-10-21 : l'instant lu tel quel suivait
 * le décalage de cette horloge), et le place sur sa propre lecture de
 * l'horloge. Un âge négatif, ou plus vieux que tout ce que la file garde,
 * revient à maintenant ; une fin ne passe jamais maintenant ni ne précède le
 * début.
 */

/**
 * L'instant de référence des cas : un lundi midi, loin d'un changement
 * d'heure.
 */
function instantsBornesMaintenant(): Carbon
{
    return Carbon::parse('2026-10-19 12:00:00');
}

dataset('débuts venus de l’appareil', [
    'sans âge' => [null, '2026-10-19 12:00:00'],
    'l’instant même' => [0, '2026-10-19 12:00:00'],
    'une heure plus tôt' => [3600, '2026-10-19 11:00:00'],
    'trente jours pile plus tôt' => [30 * 86400, '2026-09-19 12:00:00'],
    'trente jours et une seconde plus tôt' => [30 * 86400 + 1, '2026-10-19 12:00:00'],
    'une seconde après son envoi' => [-1, '2026-10-19 12:00:00'],
    'un an après son envoi' => [-365 * 86400, '2026-10-19 12:00:00'],
]);

it('prend le début de l’appareil dans les trente jours passés, sinon maintenant', function (?int $age, string $attendu): void {
    $maintenant = instantsBornesMaintenant();

    $debut = Workout::debutBorne($age, $maintenant);

    expect($debut->format('Y-m-d H:i:s'))->toBe($attendu)
        // Une copie, jamais l'instant reçu : le Carbon de Laravel est
        // mutable, et l'opération réutilise sa lecture de l'horloge.
        ->and($debut)->not->toBe($maintenant)
        ->and($maintenant->format('Y-m-d H:i:s'))->toBe('2026-10-19 12:00:00');
})->with('débuts venus de l’appareil');

/*
 * Trente jours du calendrier, comme la borne d'avant l'âge : une file qui
 * enjambe le passage à l'heure d'hiver garde un geste de trente jours pile,
 * qui a une heure de plus que trente fois vingt-quatre heures.
 */
it('compte les trente jours au calendrier, à travers le passage à l’heure d’hiver', function (): void {
    $maintenant = Carbon::parse('2026-11-10 12:00:00');
    $trenteJours = (int) Carbon::parse('2026-10-11 12:00:00')->diffInSeconds($maintenant);

    expect($trenteJours)->toBe(30 * 86400 + 3600)
        ->and(Workout::debutBorne($trenteJours, $maintenant)->format('Y-m-d H:i:s'))->toBe('2026-10-11 12:00:00')
        ->and(Workout::debutBorne($trenteJours + 1, $maintenant)->format('Y-m-d H:i:s'))->toBe('2026-11-10 12:00:00');
});

dataset('fins venues de l’appareil', [
    'sans âge' => [null, '2026-10-19 12:00:00'],
    'entre le début et maintenant' => [1800, '2026-10-19 11:30:00'],
    'le début même' => [3600, '2026-10-19 11:00:00'],
    'avant le début' => [3601, '2026-10-19 11:00:00'],
    'après son envoi' => [-1, '2026-10-19 12:00:00'],
]);

it('prend la fin de l’appareil entre le début et maintenant', function (?int $age, string $attendu): void {
    $maintenant = instantsBornesMaintenant();
    $seance = new Workout(['started_at' => '2026-10-19 11:00:00']);

    $fin = $seance->finBornee($age, $maintenant);

    expect($fin->format('Y-m-d H:i:s'))->toBe($attendu)
        ->and($fin)->not->toBe($maintenant)
        ->and($fin)->not->toBe($seance->started_at);
})->with('fins venues de l’appareil');

/*
 * Une fin bornée à maintenant sur une séance dont le début est dans le futur
 * revient au début : la durée n'est jamais négative. Les réglages refusent un
 * tel début depuis #2049 ; le panneau peut encore le poser, et une séance
 * plus ancienne le porter.
 */
it('ne termine jamais une séance avant son début, même déplacé dans le futur', function (): void {
    $maintenant = instantsBornesMaintenant();
    $seance = new Workout(['started_at' => '2026-10-19 13:00:00']);

    expect($seance->finBornee(null, $maintenant)->format('Y-m-d H:i:s'))->toBe('2026-10-19 13:00:00');
});

/*
 * Trente jours, c'est la vie d'une écriture dans la file : la borne du serveur
 * et celle du client ne doivent pas se séparer.
 */
it('borne le début à la vie d’une écriture dans la file du client', function (): void {
    $source = (string) file_get_contents(resource_path('js/Utils/ecrituresAbandonnees.js'));

    preg_match('/^export const JOURS_EN_FILE_AU_PLUS = (\d+)$/m', $source, $trouve);

    expect($trouve[1] ?? null)->toBe((string) Workout::JOURS_EN_FILE_AU_PLUS);
});

it('nomme les lignes et les séries lancées d’un modèle par leur rang, l’exercice dans la clé de ligne', function (): void {
    expect(WorkoutTemplate::cleDUneLigneLancee(0, 17))->toBe('modele-ligne:0:17')
        ->and(WorkoutTemplate::cleDUneLigneLancee(3, 5))->toBe('modele-ligne:3:5')
        ->and(WorkoutTemplate::cleDUneSerieLancee(0, 0))->toBe('modele-serie:0:0')
        ->and(WorkoutTemplate::cleDUneSerieLancee(2, 4))->toBe('modele-serie:2:4');
});

/*
 * La clé la plus longue qu'un modèle puisse produire (rangs au plafond d'un
 * modèle, identifiant d'exercice au plus grand entier) reste une clé que les
 * créations admettent : 64 caractères ASCII imprimables au plus, sans espace.
 * Ignorée, elle laisserait le rejeu doubler la ligne.
 */
it('pose des clés que les créations de ligne et de série admettent', function (): void {
    $ligne = WorkoutTemplate::cleDUneLigneLancee(WorkoutTemplate::EXERCICES_MAX - 1, PHP_INT_MAX);
    $serie = WorkoutTemplate::cleDUneSerieLancee(WorkoutTemplate::EXERCICES_MAX - 1, WorkoutTemplate::SERIES_MAX_PAR_EXERCICE - 1);

    expect(CreateSetAction::idempotencyKey(['idempotency_key' => $ligne]))->toBe($ligne)
        ->and(CreateSetAction::idempotencyKey(['idempotency_key' => $serie]))->toBe($serie);
});

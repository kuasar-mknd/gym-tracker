<?php

declare(strict_types=1);

/*
 * L'historique d'usage des complements etait execute par les tests sans etre
 * verifie : 19 des 22 mutants de `FetchSupplementsIndexAction` survivaient.
 *
 * Ce que cela voulait dire concretement — chacune de ces reecritures passait
 * la suite au vert : rendre l'historique vide, le rendre a l'envers, changer sa
 * longueur, arrondir les quantites a l'entier, retirer la cle `date` de chaque
 * entree, ou remplacer un jour sans prise par autre chose que zero.
 *
 * Les assertions ci-dessous ferment ces portes une par une, en comparant a des
 * valeurs POSEES et jamais tirees : la fabrique de `SupplementLog` tire une
 * quantite entre 1 et 3 et une date dans le mois, ce qui rendrait chacun de ces
 * tests dependant du hasard.
 */

use App\Actions\Supplements\FetchSupplementsIndexAction;
use App\Models\Supplement;
use App\Models\SupplementLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Un utilisateur, un complement, et l'horloge arretee.
 *
 * Le 15 juin est choisi loin de tout changement d'heure : `subDays()` traverse
 * une bascule sans broncher, mais `format('d/m')` sur un jour qui en dure 23 ou
 * 25 rendrait ce test faux deux fois par an, et personne ne saurait pourquoi.
 *
 * @return array{0: User, 1: Supplement}
 */
function scenePourHistorique(): array
{
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();
    $supplement = Supplement::factory()->create(['user_id' => $user->id]);

    return [$user, $supplement];
}

function consommer(User $user, Supplement $supplement, string $quand, int $quantite): void
{
    SupplementLog::create([
        'user_id' => $user->id,
        'supplement_id' => $supplement->id,
        'quantity' => $quantite,
        'consumed_at' => Carbon::parse($quand),
    ]);
}

it('rend exactement trente jours, du plus ancien au plus recent', function (): void {
    [$user] = scenePourHistorique();

    $historique = app(FetchSupplementsIndexAction::class)->execute($user)['usageHistory'];

    // Trente entrees, pas vingt-neuf ni trente-et-une : c'est la fenetre que la
    // page annonce, et la seule assertion qui tienne la borne de la boucle.
    expect($historique)->toHaveCount(30);

    // Le sens de lecture, que rien ne verifiait : la boucle descend de J-29 a
    // aujourd'hui, donc la premiere entree est la plus ancienne.
    expect($historique[0]['date'])->toBe('17/05');
    expect($historique[29]['date'])->toBe('15/06');
});

it('somme les prises du jour et rend un flottant', function (): void {
    [$user, $supplement] = scenePourHistorique();

    consommer($user, $supplement, '2026-06-15 08:00:00', 2);
    consommer($user, $supplement, '2026-06-15 20:00:00', 3);

    // 5 et non 2 : deux prises le meme jour se somment, elles ne se comptent
    // pas et la derniere n'ecrase pas la premiere.
    //
    // Et 5.0 et non 5 : `SUM()` revient de MySQL en chaine, que la conversion
    // en flottant rattrape. `toBe()` compare a l'identique, donc cette ligne
    // seule tient la conversion — sans elle, la page recevrait "5".
    $historique = app(FetchSupplementsIndexAction::class)->execute($user)['usageHistory'];

    expect($historique[29]['count'])->toBe(5.0);
    expect($historique[29]['count'])->toBeFloat();
});

it('met zero, et un flottant, sur un jour sans prise', function (): void {
    [$user, $supplement] = scenePourHistorique();

    consommer($user, $supplement, '2026-06-15 08:00:00', 1);

    $historique = app(FetchSupplementsIndexAction::class)->execute($user)['usageHistory'];

    // Un jour creux vaut 0.0 — pas `null`, pas la chaine vide, pas l'entier 0.
    // Le graphe de la page trace ce tableau tel quel.
    expect($historique[10]['count'])->toBe(0.0);
    expect($historique[10]['count'])->toBeFloat();
});

it('donne a chaque entree une date et un compte, et rien d autre', function (): void {
    [$user] = scenePourHistorique();

    $historique = app(FetchSupplementsIndexAction::class)->execute($user)['usageHistory'];

    // La forme de chaque entree, verifiee sur toutes plutot que sur la premiere :
    // une boucle qui ne remplit qu'une cle sur deux passerait un test qui ne
    // regarde qu'un echantillon.
    foreach ($historique as $entree) {
        expect(array_keys($entree))->toBe(['date', 'count']);
    }
});

it('ignore une prise anterieure a la fenetre', function (): void {
    [$user, $supplement] = scenePourHistorique();

    consommer($user, $supplement, '2026-05-16 12:00:00', 99);

    $historique = app(FetchSupplementsIndexAction::class)->execute($user)['usageHistory'];

    // 99 est intentionnellement enorme : s'il fuyait dans une entree, aucune
    // somme legitime ne pourrait le masquer.
    expect(array_column($historique, 'count'))->each->toBe(0.0);
});

it('ne compte pas les prises d un autre utilisateur', function (): void {
    [$user, $supplement] = scenePourHistorique();

    $autre = User::factory()->create();
    $sienne = Supplement::factory()->create(['user_id' => $autre->id]);
    consommer($autre, $sienne, '2026-06-15 08:00:00', 7);

    $historique = app(FetchSupplementsIndexAction::class)->execute($user)['usageHistory'];

    expect($historique[29]['count'])->toBe(0.0);
});

it('charge le dernier journal en une requete et non un par complement', function (): void {
    [$user] = scenePourHistorique();

    Supplement::factory()->count(4)->create(['user_id' => $user->id]);

    $requetes = 0;
    DB::listen(function () use (&$requetes): void {
        $requetes++;
    });

    app(FetchSupplementsIndexAction::class)->execute($user);

    // Trois requetes, et ce nombre ne doit pas dependre du nombre de
    // complements : la liste, les derniers journaux en une fois, et
    // l'historique d'usage. Sans le chargement anticipe, chaque complement en
    // ajoute une — un cout qu'aucune assertion de contenu ne peut voir, d'ou
    // un compte exact plutot qu'un plafond : un plafond genereux laisse
    // repasser le N+1 des que la liste s'allonge.
    expect($requetes)->toBe(3);
});

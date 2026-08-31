<?php

declare(strict_types=1);

/*
 * Le calendrier etait execute par la suite sans etre lu : 22 des 38 mutants de
 * `FetchCalendarEventsAction` survivaient (score 42,1 %).
 *
 * Ce que cela voulait dire concretement — chacune de ces reecritures passait la
 * suite au vert : rendre tous les apercus d'exercices vides, en montrer deux ou
 * quatre au lieu de trois, retirer la cle `date`, `started_at`,
 * `exercises_count` ou `preview_exercises` de chaque seance, appeler toutes les
 * seances « Seance » quel que soit leur nom, retirer `name` du `select`,
 * annoncer zero exercice partout, retirer `date`, `mood_score` ou `has_note` de
 * chaque journal, et — les quatre reecritures de la ligne 131 — repondre
 * « note » sur un journal vide ou « pas de note » sur un journal ecrit.
 *
 * Les assertions ci-dessous ferment ces portes une par une, en comparant a des
 * valeurs POSEES : la fabrique de `Workout` tire un nom au hasard (une date
 * formatee) et celle de `DailyJournal` distribue des dates de 2015, ce qui
 * rendrait chacun de ces tests dependant de ce que la fabrique a bien voulu
 * produire.
 */

use App\Actions\Calendar\FetchCalendarEventsAction;
use App\Models\DailyJournal;
use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Un utilisateur, et l'horloge arretee au milieu du mois observe.
 *
 * Juin 2026 est choisi loin des deux bascules d'heure (fin mars, fin octobre) :
 * `toIso8601String()` ecrit le decalage, et un mois a cheval sur un changement
 * d'heure rendrait l'attendu faux deux fois par an sans que personne ne sache
 * pourquoi.
 */
function sceneCalendrier(): User
{
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    return User::factory()->create();
}

function seanceDuCalendrier(User $user, string $quand, ?string $nom = 'Haut du corps'): Workout
{
    return Workout::factory()->create([
        'user_id' => $user->id,
        'name' => $nom,
        'started_at' => Carbon::parse($quand),
    ]);
}

function exerciceDeLaSeance(Workout $seance, string $nom, int $ordre): void
{
    WorkoutLine::factory()->create([
        'workout_id' => $seance->id,
        'exercise_id' => Exercise::factory()->create(['name' => $nom])->id,
        'order' => $ordre,
    ]);
}

function journalDuCalendrier(User $user, string $jour, ?string $contenu, ?int $humeur = 3): DailyJournal
{
    return DailyJournal::factory()->create([
        'user_id' => $user->id,
        'date' => $jour,
        'content' => $contenu,
        'mood_score' => $humeur,
    ]);
}

/** @return Collection<int, array{id: int, name: string, date: non-falsy-string, started_at: string, exercises_count: int<0, max>, preview_exercises: array<int, string>}> */
function seancesDeJuin(User $user): Collection
{
    return app(FetchCalendarEventsAction::class)->execute($user, 2026, 6)['workouts'];
}

/** @return Collection<int, array{id: int, date: non-falsy-string, mood_score: int|null, has_note: bool}> */
function journauxDeJuin(User $user): Collection
{
    return app(FetchCalendarEventsAction::class)->execute($user, 2026, 6)['journals'];
}

it('ne rend que les seances du mois demande, premiere et derniere seconde comprises', function (): void {
    $user = sceneCalendrier();

    $premiere = seanceDuCalendrier($user, '2026-06-01 00:00:00');
    $derniere = seanceDuCalendrier($user, '2026-06-30 23:59:59');

    // Les deux voisines immediates : une seconde de part et d'autre du mois.
    seanceDuCalendrier($user, '2026-05-31 23:59:59');
    seanceDuCalendrier($user, '2026-07-01 00:00:00');

    $seances = seancesDeJuin($user);

    // Deux, pas trois ni quatre : c'est la fenetre que la page annonce, et la
    // seule assertion qui tienne les deux bornes de `whereBetween`.
    expect($seances)->toHaveCount(2);
    expect($seances->pluck('id')->all())->toEqualCanonicalizing([$premiere->id, $derniere->id]);
});

it('ne rend que les journaux du mois demande, premier et dernier jour compris', function (): void {
    $user = sceneCalendrier();

    journalDuCalendrier($user, '2026-06-01', 'Premier du mois');
    journalDuCalendrier($user, '2026-06-30', 'Dernier du mois');
    journalDuCalendrier($user, '2026-05-31', 'La veille');
    journalDuCalendrier($user, '2026-07-01', 'Le lendemain');

    $journaux = journauxDeJuin($user);

    expect($journaux)->toHaveCount(2);
    expect($journaux->pluck('date')->all())->toEqualCanonicalizing(['2026-06-01', '2026-06-30']);
});

it('rend les trois premiers exercices d une seance, dans l ordre de la seance', function (): void {
    $user = sceneCalendrier();
    $seance = seanceDuCalendrier($user, '2026-06-11 18:30:00');

    exerciceDeLaSeance($seance, 'Developpe couche', 0);
    exerciceDeLaSeance($seance, 'Squat', 1);
    exerciceDeLaSeance($seance, 'Souleve de terre', 2);
    exerciceDeLaSeance($seance, 'Traction', 3);

    $rendue = seancesDeJuin($user)->sole();

    /*
     * Trois noms exactement, et ces trois-la dans cet ordre. Un apercu vide,
     * un apercu de deux ou de quatre, ou un apercu pris dans le desordre
     * passaient tous la suite jusqu'ici : rien ne regardait ce tableau.
     */
    expect($rendue['preview_exercises'])->toBe(['Developpe couche', 'Squat', 'Souleve de terre']);

    // Le compte, lui, porte sur toutes les lignes et pas seulement sur les
    // trois montrees : sans cette assertion, annoncer zero exercice partout
    // passait.
    expect($rendue['exercises_count'])->toBe(4);
});

it('rend un apercu vide et un compte a zero pour une seance sans exercice', function (): void {
    $user = sceneCalendrier();
    seanceDuCalendrier($user, '2026-06-11 18:30:00');

    $rendue = seancesDeJuin($user)->sole();

    // Le repli du `??` : une seance absente de la table des apercus recoit un
    // tableau vide, pas `null` — la page itere dessus telle quelle.
    expect($rendue['preview_exercises'])->toBe([]);
    expect($rendue['exercises_count'])->toBe(0);
});

it('rend le nom, le jour et l heure poses sur la seance', function (): void {
    $user = sceneCalendrier();
    $seance = seanceDuCalendrier($user, '2026-06-11 18:30:00', 'Jambes et gainage');

    $rendue = seancesDeJuin($user)->sole();

    expect($rendue['id'])->toBe($seance->id);

    // Le nom REEL, et non le repli : `name` doit rester dans le `select` et le
    // `?? 'Seance'` ne doit pas ecraser ce que l'utilisateur a saisi.
    expect($rendue['name'])->toBe('Jambes et gainage');

    // Deux representations de la meme date, chacune lue par un endroit
    // different de la page : la case du calendrier compare `date`, l'infobulle
    // affiche l'heure. Le decalage +02:00 est celui de Paris en juin.
    expect($rendue['date'])->toBe('2026-06-11');
    expect($rendue['started_at'])->toBe('2026-06-11T18:30:00+02:00');
});

it('appelle « Seance » une seance restee sans nom', function (): void {
    $user = sceneCalendrier();
    seanceDuCalendrier($user, '2026-06-11 18:30:00', null);

    expect(seancesDeJuin($user)->sole()['name'])->toBe('Séance');
});

it('donne a chaque seance les six memes cles, et rien d autre', function (): void {
    $user = sceneCalendrier();

    $avecExercices = seanceDuCalendrier($user, '2026-06-11 18:30:00');
    exerciceDeLaSeance($avecExercices, 'Rowing', 0);
    seanceDuCalendrier($user, '2026-06-12 09:00:00');

    $seances = seancesDeJuin($user);

    expect($seances)->toHaveCount(2);

    /*
     * La forme verifiee sur TOUTES les entrees plutot que sur la premiere : une
     * projection qui ne remplit une cle que dans un cas sur deux passerait un
     * test qui ne regarde qu'un echantillon.
     */
    foreach ($seances as $entree) {
        expect(array_keys($entree))->toBe([
            'id',
            'name',
            'date',
            'started_at',
            'exercises_count',
            'preview_exercises',
        ]);
    }
});

it('donne a chaque journal les quatre memes cles, et rien d autre', function (): void {
    $user = sceneCalendrier();

    journalDuCalendrier($user, '2026-06-08', 'Bonne seance');
    journalDuCalendrier($user, '2026-06-09', null);

    $journaux = journauxDeJuin($user);

    expect($journaux)->toHaveCount(2);

    foreach ($journaux as $entree) {
        expect(array_keys($entree))->toBe(['id', 'date', 'mood_score', 'has_note']);
    }
});

it('rend le jour et l humeur poses sur le journal', function (): void {
    $user = sceneCalendrier();
    $journal = journalDuCalendrier($user, '2026-06-08', 'Bonne seance', 4);

    $rendu = journauxDeJuin($user)->sole();

    expect($rendu['id'])->toBe($journal->id);
    expect($rendu['date'])->toBe('2026-06-08');

    // 4 et pas « une valeur quelconque » : la pastille d'humeur de la page se
    // colore d'apres ce nombre.
    expect($rendu['mood_score'])->toBe(4);
});

/*
 * `has_note` decide si la page dessine l'icone de note. Les quatre reecritures
 * de cette ligne — les deux `!==` retournes en `===`, le `&&` change en `||`, et
 * la chaine vide remplacee par une autre chaine — se distinguent uniquement en
 * comparant les trois etats possibles de la colonne, qui est `TEXT NULL`.
 */
it('dit « note » pour un journal ecrit, et « pas de note » pour un journal vide ou absent', function (): void {
    $user = sceneCalendrier();

    journalDuCalendrier($user, '2026-06-08', 'Bonne seance, epaules fatiguees');
    journalDuCalendrier($user, '2026-06-09', null);
    journalDuCalendrier($user, '2026-06-10', '');

    $notes = journauxDeJuin($user)->sortBy('date')->pluck('has_note', 'date')->all();

    /*
     * Les trois etats compares d'un coup, et a l'identique :
     *  - un journal ecrit porte une note ;
     *  - sans ce jour a `null`, `content !== null || content !== ''` passait, et
     *    la page annoncait une note sur un jour ou rien n'avait ete ecrit ;
     *  - sans le jour a la chaine vide, celle-ci comptait comme une note — c'est
     *    exactement ce que produit un champ ouvert puis referme sans rien saisir.
     */
    expect($notes)->toBe([
        '2026-06-08' => true,
        '2026-06-09' => false,
        '2026-06-10' => false,
    ]);
});

/*
 * Les seances anciennes portent toutes `order = 0` : la colonne est
 * `NOT NULL DEFAULT 0` et rien ne l'a renseignee avant que le reordonnancement
 * n'existe. Trier sur elle seule ne departage donc rien, et les TROIS
 * exercices montres dans l'apercu du mois sont ceux que la base a bien voulu
 * rendre — ils peuvent changer d'un chargement a l'autre.
 *
 * L'assertion porte sur la FORME de la requete et non sur les lignes rendues,
 * pour la meme raison que dans `OrdreDesExercicesDuModeleTest` : l'index
 * `(workout_id, order)` porte deja la clef primaire, si bien que MySQL rend
 * aujourd'hui le bon ordre par accident de plan. Un temoin de comportement
 * passerait donc avant comme apres le correctif.
 *
 * Le departage est gratuit — mesure a 12 000 lignes, 28 lectures de clef et
 * 167 de suite, sans tri, avec et sans lui.
 */
it('departe l apercu par identifiant, faute de quoi les seances anciennes en rendent trois au hasard', function (): void {
    $user = sceneCalendrier();
    $seance = seanceDuCalendrier($user, '2026-06-12 18:30:00');

    foreach (['Developpe couche', 'Squat', 'Souleve de terre', 'Traction'] as $nom) {
        exerciceDeLaSeance($seance, $nom, 0);
    }

    $requetes = [];
    \Illuminate\Support\Facades\DB::listen(function (\Illuminate\Database\Events\QueryExecuted $execute) use (&$requetes): void {
        $requetes[] = $execute->sql;
    });

    $rendue = seancesDeJuin($user)->sole();

    $surLesLignes = array_values(array_filter(
        $requetes,
        static fn (string $sql): bool => str_contains($sql, 'from `workout_lines`') && str_contains($sql, 'order by')
    ));

    expect($surLesLignes)->not->toBeEmpty()
        ->and($surLesLignes[0])->toContain('order by `workout_id` asc, `order` asc, `id` asc');

    // Et le contrat que ce departage rend enfin vrai.
    expect($rendue['preview_exercises'])->toBe(['Developpe couche', 'Squat', 'Souleve de terre']);
});

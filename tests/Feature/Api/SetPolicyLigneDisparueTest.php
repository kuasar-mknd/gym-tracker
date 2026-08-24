<?php

declare(strict_types=1);

use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/**
 * Une serie dont la ligne a disparu.
 *
 * `SetPolicy` lit la propriete du proprietaire a travers deux relations —
 * `$set->workoutLine->workout->user_id`. Le premier maillon peut manquer : la
 * cle etrangere est en ON DELETE CASCADE, donc supprimer la ligne emporte la
 * serie, et une requete lente qui a deja resolu son modele lit ensuite une
 * relation qui ne renvoie plus rien. Sans garde, la politique deferencait
 * `null` et l'API rendait 500.
 *
 * La reponse due est 404, et non 403. Le gardien de `bootstrap/app.php` (#1418)
 * transforme en « Resource not found. » tout refus visant une ressource que
 * l'appelant ne peut pas voir, et il tranche en interrogeant `view`. Une serie
 * sans ligne n'a plus de proprietaire etablissable : `view` refuse, donc la
 * ressource est invisible, donc la reponse est celle qu'on donne a un id
 * inconnu. C'est aussi la seule qui ne renseigne pas : un 403 sur cette route
 * affirmerait que la serie existe.
 *
 * Noter que le gardien lui-meme appelle `view`. Une garde posee sur `update`
 * seule ne suffirait pas : le refus remonterait au gardien, qui rejouerait le
 * deferencement pour son propre compte.
 */

/**
 * L'etat incoherent, tel que la course le produit.
 *
 * Aucune fabrique ne peut le fabriquer directement : la contrainte refuse une
 * serie sans ligne. On supprime donc la ligne contraintes levees, ce qui laisse
 * la serie orpheline au lieu de la faire disparaitre avec elle — exactement la
 * fenetre pendant laquelle la requete lente tient encore son modele.
 */
/**
 * @param  array<string, mixed>  $attributs  ce que la serie doit porter, plutot que le tirage de la fabrique
 */
function serieOrpheline(User $user, array $attributs = []): Set
{
    $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    $set = Set::factory()->create(['workout_line_id' => $workoutLine->id, ...$attributs]);

    Schema::withoutForeignKeyConstraints(
        fn () => WorkoutLine::query()->whereKey($workoutLine->id)->delete()
    );

    expect(Set::query()->whereKey($set->id)->exists())->toBeTrue()
        ->and(WorkoutLine::query()->whereKey($workoutLine->id)->exists())->toBeFalse();

    return $set;
}

it('rend 404 et non 500 en consultant une serie dont la ligne a disparu', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $set = serieOrpheline($user);

    $response = $this->getJson(route('api.v1.sets.show', $set));

    $response->assertNotFound();
    $response->assertExactJson(['message' => 'Resource not found.']);
});

it('rend 404 et non 500 en modifiant une serie dont la ligne a disparu', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    /*
     * La valeur de depart est POSEE, pas tiree.
     *
     * L'assertion « la serie n'a pas bouge » etait ecrite « reps != 12 », la
     * valeur envoyee. Elle tombait donc le jour ou la fabrique tirait 12 — ce
     * qui est arrive sur la passe nocturne de mutation, et a bloque la
     * publication de l'image. Une assertion qui depend d'un tirage n'est pas un
     * garde : elle echoue sans defaut, et le jour ou elle a raison personne ne
     * la croit.
     */
    $set = serieOrpheline($user, ['reps' => 8]);

    $response = $this->patchJson(route('api.v1.sets.update', $set), ['reps' => 12]);

    $response->assertNotFound();
    $response->assertExactJson(['message' => 'Resource not found.']);
    expect((int) Set::query()->whereKey($set->id)->value('reps'))->toBe(8);
});

it('rend 404 et non 500 en supprimant une serie dont la ligne a disparu', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $set = serieOrpheline($user);

    $response = $this->deleteJson(route('api.v1.sets.destroy', $set));

    $response->assertNotFound();
    $response->assertExactJson(['message' => 'Resource not found.']);
    expect(Set::query()->whereKey($set->id)->exists())->toBeTrue();
});

/**
 * La course elle-meme, sans lever aucune contrainte.
 *
 * Ici la base reste coherente — la cascade emporte bien la serie — et c'est
 * l'instance en memoire qui survit a sa ligne, ce qui est litteralement ce que
 * vit une requete lente. La relation n'a jamais ete touchee avant la
 * suppression, sinon elle resterait en cache sur l'instance et la course ne se
 * jouerait pas.
 */
it('refuse sans lever, quand la ligne est supprimee apres la resolution du modele', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    $creee = Set::factory()->create(['workout_line_id' => $workoutLine->id]);

    // Relu depuis la base : l'instance rendue par la fabrique porte deja la
    // relation en cache — l'observateur de `saved` la traverse — et ne verrait
    // donc jamais la ligne disparaitre. C'est l'instance fraiche, celle que la
    // liaison de modele donne au controleur, qui subit la course.
    $set = Set::query()->findOrFail($creee->id);

    $workoutLine->delete();

    expect(Set::query()->whereKey($set->id)->exists())->toBeFalse();

    expect($user->can('view', $set))->toBeFalse()
        ->and($user->can('update', $set))->toBeFalse()
        ->and($user->can('delete', $set))->toBeFalse();
});

<?php

declare(strict_types=1);

use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/**
 * Un enregistrement dont le parent a disparu.
 *
 * Meme defaut que celui corrige pour `SetPolicy` (#1535), aux memes lignes, sur
 * les trois politiques que la sonde avait relevees : `WorkoutLinePolicy`,
 * `WorkoutTemplateLinePolicy` et `HabitLogPolicy` deferencaient une chaine de
 * relations sans garde et rendaient **500**.
 *
 * Le maillon est facultatif par chronologie, non par modelisation : la cle
 * etrangere est NOT NULL et en ON DELETE CASCADE, donc la base ne garde jamais
 * d'orphelin durablement. Mais la liaison de route resout l'enfant d'abord et
 * la politique lit la relation ensuite — une suppression du parent qui se
 * glisse entre les deux laisse la requete avec une instance dont la relation
 * ne renvoie plus rien.
 *
 * La reponse due est 404 et non 403 : le gardien de `bootstrap/app.php`
 * (#1418) interroge `view` pour savoir si le refus porte sur une ressource
 * invisible, et un enfant sans parent n'a plus de proprietaire etablissable.
 * Un 403 affirmerait que la ressource existe — exactement l'oracle que #1418 a
 * ferme.
 *
 * Noter que le gardien appelle `view` LUI-MEME. Une garde posee sur `update`
 * seule n'aurait deplace la panne que d'un cran, du controleur vers le
 * gestionnaire d'exception.
 */

/**
 * L'etat incoherent, tel que la course le produit.
 *
 * Aucune fabrique ne peut le fabriquer directement : la contrainte refuse un
 * enfant sans parent. On supprime donc le parent contraintes levees, ce qui
 * laisse l'enfant orphelin au lieu de le faire disparaitre avec lui — la
 * fenetre exacte pendant laquelle la requete lente tient encore son modele.
 *
 * Ne rend rien : l'appelant tient deja l'enfant, et le rendre obligerait a un
 * type generique que le natif ne sait pas exprimer sans se contredire.
 */
function orphelin(\Illuminate\Database\Eloquent\Model $enfant, \Illuminate\Database\Eloquent\Model $parent): void
{
    Schema::withoutForeignKeyConstraints(
        fn () => $parent->newQuery()->whereKey($parent->getKey())->delete()
    );

    expect($enfant->newQuery()->whereKey($enfant->getKey())->exists())->toBeTrue()
        ->and($parent->newQuery()->whereKey($parent->getKey())->exists())->toBeFalse();
}

it('rend 404 et non 500 pour une ligne de séance dont la séance a disparu', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    orphelin($ligne, $workout);

    $this->getJson(route('api.v1.workout-lines.show', $ligne))
        ->assertNotFound()
        ->assertExactJson(['message' => 'Resource not found.']);

    $this->deleteJson(route('api.v1.workout-lines.destroy', $ligne))
        ->assertNotFound()
        ->assertExactJson(['message' => 'Resource not found.']);

    $this->assertDatabaseHas('workout_lines', ['id' => $ligne->id]);
});

it('rend 404 et non 500 pour une ligne de modèle dont le modèle a disparu', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $modele = WorkoutTemplate::factory()->create(['user_id' => $user->id]);
    $ligne = WorkoutTemplateLine::factory()->create(['workout_template_id' => $modele->id]);
    orphelin($ligne, $modele);

    $this->getJson(route('api.v1.workout-template-lines.show', $ligne))
        ->assertNotFound()
        ->assertExactJson(['message' => 'Resource not found.']);

    $this->deleteJson(route('api.v1.workout-template-lines.destroy', $ligne))
        ->assertNotFound()
        ->assertExactJson(['message' => 'Resource not found.']);

    $this->assertDatabaseHas('workout_template_lines', ['id' => $ligne->id]);
});

it('rend 404 et non 500 pour un relevé d’habitude dont l’habitude a disparu', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $habitude = Habit::factory()->create(['user_id' => $user->id]);
    $releve = HabitLog::factory()->create(['habit_id' => $habitude->id]);
    orphelin($releve, $habitude);

    $this->getJson(route('api.v1.habit-logs.show', $releve))
        ->assertNotFound()
        ->assertExactJson(['message' => 'Resource not found.']);

    $this->deleteJson(route('api.v1.habit-logs.destroy', $releve))
        ->assertNotFound()
        ->assertExactJson(['message' => 'Resource not found.']);

    $this->assertDatabaseHas('habit_logs', ['id' => $releve->id]);
});

/**
 * La course elle-meme, sans lever aucune contrainte.
 *
 * Ici la base reste coherente — la cascade emporte bien l'enfant — et c'est
 * l'instance en memoire qui survit a son parent, ce qui est litteralement ce
 * que vit une requete lente. La relation n'est jamais touchee avant la
 * suppression, sinon elle resterait en cache sur l'instance et la course ne se
 * jouerait pas : d'ou la relecture par `findOrFail`.
 */
it('refuse sans lever, quand le parent est supprimé après la résolution du modèle', function (): void {
    $user = User::factory()->create();

    $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
    $creee = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    $ligne = WorkoutLine::query()->findOrFail($creee->id);

    $workout->delete();

    expect($user->can('view', $ligne))->toBeFalse()
        ->and($user->can('update', $ligne))->toBeFalse()
        ->and($user->can('delete', $ligne))->toBeFalse();
});

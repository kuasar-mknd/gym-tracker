<?php

declare(strict_types=1);

use App\Models\Achievement;
use App\Models\Admin;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\Habit;
use App\Models\Set;
use App\Models\Supplement;
use App\Models\SupplementLog;
use App\Models\User;
use App\Models\UserAchievement;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use App\Models\WorkoutTemplateSet;
use App\Policies\AchievementPolicy;
use App\Policies\ExercisePolicy;
use App\Policies\GoalPolicy;
use App\Policies\HabitLogPolicy;
use App\Policies\SetPolicy;
use App\Policies\SupplementLogPolicy;
use App\Policies\SupplementPolicy;
use App\Policies\UserAchievementPolicy;
use App\Policies\WorkoutPolicy;
use App\Policies\WorkoutTemplateLinePolicy;
use App\Policies\WorkoutTemplateSetPolicy;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function adminGranted(array $abilities): Admin
{
    $admin = Admin::factory()->create();

    foreach ($abilities as $ability) {
        Permission::findOrCreate($ability, 'admin');
    }

    if ($abilities !== []) {
        $admin->givePermissionTo($abilities);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $admin->fresh();
}

describe('a finished workout is frozen', function (): void {
    it('lets the owner edit a workout while it is still running', function (): void {
        $owner = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $owner->id, 'ended_at' => null]);

        expect(new WorkoutPolicy()->update($owner, $workout))->toBeTrue();
    });

    it('refuses to edit a workout once it has ended', function (): void {
        $owner = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $owner->id, 'ended_at' => now()]);

        expect(new WorkoutPolicy()->update($owner, $workout))->toBeFalse();
    });

    /**
     * Deleting is deliberately not frozen: a finished session can still be
     * thrown away. Asserted so the asymmetry survives anyone tidying up.
     */
    it('still lets the owner delete a workout that has ended', function (): void {
        $owner = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $owner->id, 'ended_at' => now()]);

        expect(new WorkoutPolicy()->delete($owner, $workout))->toBeTrue();
    });

    it('freezes the sets of a finished workout, and only those', function (): void {
        $owner = User::factory()->create();
        $open = Set::factory()
            ->for(WorkoutLine::factory()->for(Workout::factory()->create(['user_id' => $owner->id, 'ended_at' => null]))->create())
            ->create()
            ->load('workoutLine.workout');
        $ended = Set::factory()
            ->for(WorkoutLine::factory()->for(Workout::factory()->create(['user_id' => $owner->id, 'ended_at' => now()]))->create())
            ->create()
            ->load('workoutLine.workout');
        $policy = new SetPolicy();

        expect($policy->update($owner, $open))->toBeTrue()
            ->and($policy->delete($owner, $open))->toBeTrue()
            ->and($policy->update($owner, $ended))->toBeFalse()
            ->and($policy->delete($owner, $ended))->toBeFalse()
            // Reading history stays allowed whatever the workout's state.
            ->and($policy->view($owner, $ended))->toBeTrue();
    });
});

describe('creating a child row against a parent', function (): void {
    it('allows a set on the owner\'s running workout line only', function (): void {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $open = WorkoutLine::factory()->for(Workout::factory()->create(['user_id' => $owner->id, 'ended_at' => null]))->create()->load('workout');
        $ended = WorkoutLine::factory()->for(Workout::factory()->create(['user_id' => $owner->id, 'ended_at' => now()]))->create()->load('workout');
        $policy = new SetPolicy();

        expect($policy->create($owner, $open))->toBeTrue()
            ->and($policy->create($owner, $ended))->toBeFalse()
            ->and($policy->create($intruder, $open))->toBeFalse()
            // No parent given: the form is being opened, nothing is being written yet.
            ->and($policy->create($owner))->toBeTrue();
    });

    it('allows a habit log on the owner\'s habit only', function (): void {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $habit = Habit::factory()->create(['user_id' => $owner->id]);
        $policy = new HabitLogPolicy();

        expect($policy->create($owner, $habit))->toBeTrue()
            ->and($policy->create($intruder, $habit))->toBeFalse()
            ->and($policy->create($owner))->toBeTrue();
    });

    it('allows a template line on the owner\'s template only', function (): void {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $template = WorkoutTemplate::factory()->create(['user_id' => $owner->id]);
        $policy = new WorkoutTemplateLinePolicy();

        expect($policy->create($owner, $template))->toBeTrue()
            ->and($policy->create($intruder, $template))->toBeFalse()
            ->and($policy->create($owner))->toBeTrue();
    });

    it('allows a template set on the owner\'s template line only', function (): void {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $line = WorkoutTemplateLine::factory()
            ->for(WorkoutTemplate::factory()->create(['user_id' => $owner->id]))
            ->create()
            ->load('workoutTemplate');
        $policy = new WorkoutTemplateSetPolicy();

        expect($policy->create($owner, $line))->toBeTrue()
            ->and($policy->create($intruder, $line))->toBeFalse()
            ->and($policy->create($owner))->toBeTrue();
    });

    /**
     * WorkoutTemplateSetPolicy walks the chain with `?->`. A broken chain must
     * read as "no owner", i.e. denied, never as "owner matches null".
     */
    it('denies a template set whose chain up to the template is broken', function (): void {
        $user = User::factory()->create();
        $orphan = WorkoutTemplateSet::factory()->make();
        $orphan->setRelation('workoutTemplateLine', null);
        $policy = new WorkoutTemplateSetPolicy();

        expect($policy->view($user, $orphan))->toBeFalse()
            ->and($policy->update($user, $orphan))->toBeFalse()
            ->and($policy->delete($user, $orphan))->toBeFalse();
    });
});

describe('the shared exercise catalogue', function (): void {
    it('shows a catalogue exercise to everyone but lets nobody edit it', function (): void {
        $user = User::factory()->create();
        $catalogue = Exercise::factory()->create(['user_id' => null]);
        $policy = new ExercisePolicy();

        expect($catalogue->user_id)->toBeNull()
            ->and($policy->view($user, $catalogue))->toBeTrue()
            ->and($policy->update($user, $catalogue))->toBeFalse()
            ->and($policy->delete($user, $catalogue))->toBeFalse();
    });

    it('keeps a user\'s own exercise to themselves', function (): void {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $exercise = Exercise::factory()->create(['user_id' => $owner->id]);
        $policy = new ExercisePolicy();

        expect($policy->view($owner, $exercise))->toBeTrue()
            ->and($policy->update($owner, $exercise))->toBeTrue()
            ->and($policy->delete($owner, $exercise))->toBeTrue()
            ->and($policy->view($intruder, $exercise))->toBeFalse()
            ->and($policy->update($intruder, $exercise))->toBeFalse()
            ->and($policy->delete($intruder, $exercise))->toBeFalse();
    });
});

describe('the admin branch bypasses ownership, not permissions', function (): void {
    it('lets an admin holding View:Workout read a workout belonging to somebody else', function (): void {
        $stranger = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $stranger->id]);
        $policy = new WorkoutPolicy();

        expect($policy->view(adminGranted(['View:Workout']), $workout))->toBeTrue()
            ->and($policy->view(adminGranted(['Update:Workout', 'Delete:Workout']), $workout))->toBeFalse();
    });

    it('lets an admin holding Update:Workout edit a workout that has already ended', function (): void {
        $stranger = User::factory()->create();
        $workout = Workout::factory()->create(['user_id' => $stranger->id, 'ended_at' => now()]);
        $policy = new WorkoutPolicy();

        expect($policy->update(adminGranted(['Update:Workout']), $workout))->toBeTrue()
            ->and($policy->update(adminGranted(['View:Workout']), $workout))->toBeFalse();
    });

    it('lets an admin holding View:Exercise read another user\'s private exercise', function (): void {
        $stranger = User::factory()->create();
        $exercise = Exercise::factory()->create(['user_id' => $stranger->id]);
        $policy = new ExercisePolicy();

        expect($policy->view(adminGranted(['View:Exercise']), $exercise))->toBeTrue()
            ->and($policy->view(adminGranted([]), $exercise))->toBeFalse();
    });

    it('gives an application user free rein over the collection level abilities', function (): void {
        $user = User::factory()->create();

        expect(new WorkoutPolicy()->viewAny($user))->toBeTrue()
            ->and(new WorkoutPolicy()->create($user))->toBeTrue()
            ->and(new ExercisePolicy()->viewAny($user))->toBeTrue()
            ->and(new ExercisePolicy()->create($user))->toBeTrue();
    });
});

describe('achievements are read only for the people who earn them', function (): void {
    it('lets an application user read achievements but never write them', function (): void {
        $user = User::factory()->create();
        $policy = new AchievementPolicy();

        expect($policy->viewAny($user))->toBeTrue()
            ->and($policy->view($user))->toBeTrue()
            ->and($policy->update($user))->toBeFalse()
            ->and($policy->delete($user))->toBeFalse()
            // create() has no user branch at all, so it falls through to a gate nothing defines.
            ->and($policy->create($user))->toBeFalse();
    });

    it('lets an admin holding the achievement abilities write them', function (): void {
        $policy = new AchievementPolicy();

        expect($policy->create(adminGranted(['Create:Achievement'])))->toBeTrue()
            ->and($policy->update(adminGranted(['Update:Achievement'])))->toBeTrue()
            ->and($policy->delete(adminGranted(['Delete:Achievement'])))->toBeTrue()
            ->and($policy->create(adminGranted(['Update:Achievement', 'Delete:Achievement'])))->toBeFalse()
            ->and($policy->update(adminGranted(['Create:Achievement', 'Delete:Achievement'])))->toBeFalse()
            ->and($policy->delete(adminGranted(['Create:Achievement', 'Update:Achievement'])))->toBeFalse();
    });

    it('shows an earned achievement to its owner and to nobody else', function (): void {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $earned = UserAchievement::create([
            'user_id' => $owner->id,
            'achievement_id' => Achievement::factory()->create()->id,
            'achieved_at' => now(),
        ]);
        $policy = new UserAchievementPolicy();

        expect($policy->view($owner, $earned))->toBeTrue()
            ->and($policy->view($intruder, $earned))->toBeFalse();
    });

    it('never lets anyone hand themselves an achievement', function (): void {
        $policy = new UserAchievementPolicy();

        expect($policy->create())->toBeFalse()
            ->and($policy->update())->toBeFalse()
            ->and($policy->delete())->toBeFalse()
            ->and($policy->viewAny())->toBeTrue();
    });
});

/**
 * Un identifiant que jamais un administrateur ne portera.
 *
 * Les deux branches d'une policy repondent la meme chose quand elles regardent
 * le meme entier par hasard : la branche de propriete compare `$authUser->id`
 * au `user_id` de l'enregistrement, et un administrateur fraichement cree porte
 * l'identifiant 1, comme le premier utilisateur. Un test ecrit sans y penser
 * passe alors AUSSI avec la garde `instanceof` retiree, et ne dit donc rien.
 *
 * Epingler l'identifiant du proprietaire ecarte la coincidence, plutot que
 * d'esperer qu'elle ne se produise pas.
 */
const IDENTIFIANT_HORS_DE_PORTEE = 9_000;

/**
 * La garde `instanceof \App\Models\User` que porte presque chaque methode de
 * policy n'etait exercee QUE par des utilisateurs de l'application.
 *
 * Remplacer cette garde par `true` ne faisait donc tomber aucune assertion :
 * seize mutants y survivaient. Ce n'est pas un manque de couverture — ces
 * lignes sont executees a chaque test de policy — c'est de la couverture qui ne
 * verifie rien.
 *
 * Ce que la garde decide est pourtant le coeur du modele d'autorisation : un
 * utilisateur de l'application est juge sur ce qu'il POSSEDE, un administrateur
 * de panneau sur ce qu'on lui a ACCORDE. Les deux chemins ne doivent jamais se
 * croiser, et c'est cela que les tests ci-dessous fixent.
 */
describe('les deux chemins d\'autorisation ne se croisent jamais', function (): void {
    /**
     * Les abilites de collection : l'utilisateur passe toujours, l'administrateur
     * seulement s'il porte la permission. Sans la garde, l'administrateur
     * passerait lui aussi sans rien detenir.
     */
    it('n\'ouvre les abilites de collection a un administrateur que sur permission', function (): void {
        $journal = new SupplementLogPolicy();

        expect($journal->viewAny(adminGranted(['ViewAny:SupplementLog'])))->toBeTrue()
            ->and($journal->viewAny(adminGranted([])))->toBeFalse()
            ->and($journal->create(adminGranted(['Create:SupplementLog'])))->toBeTrue()
            ->and($journal->create(adminGranted([])))->toBeFalse();
    });

    /**
     * Les abilites sur un enregistrement : l'administrateur les obtient par
     * permission, jamais par propriete. Le proprietaire porte un identifiant
     * hors de portee pour que la branche de propriete reponde NON la ou la
     * branche des permissions repond OUI — sans quoi les deux se confondent.
     */
    it('juge un administrateur sur ses permissions, jamais sur la propriete', function (): void {
        $proprietaire = User::factory()->create(['id' => IDENTIFIANT_HORS_DE_PORTEE]);
        $journal = SupplementLog::factory()->create([
            'user_id' => $proprietaire->id,
            'supplement_id' => Supplement::factory()->create(['user_id' => $proprietaire->id])->id,
        ]);
        $policy = new SupplementLogPolicy();

        expect($policy->view(adminGranted(['View:SupplementLog']), $journal))->toBeTrue()
            ->and($policy->view(adminGranted([]), $journal))->toBeFalse()
            ->and($policy->update(adminGranted(['Update:SupplementLog']), $journal))->toBeTrue()
            ->and($policy->update(adminGranted([]), $journal))->toBeFalse()
            ->and($policy->delete(adminGranted(['Delete:SupplementLog']), $journal))->toBeTrue()
            ->and($policy->delete(adminGranted([]), $journal))->toBeFalse();
    });

    it('applique la meme separation aux complements et aux objectifs', function (): void {
        $proprietaire = User::factory()->create(['id' => IDENTIFIANT_HORS_DE_PORTEE]);
        $complement = Supplement::factory()->create(['user_id' => $proprietaire->id]);
        $objectif = Goal::factory()->create(['user_id' => $proprietaire->id]);

        expect(new SupplementPolicy()->view(adminGranted(['View:Supplement']), $complement))->toBeTrue()
            ->and(new SupplementPolicy()->view(adminGranted([]), $complement))->toBeFalse()
            ->and(new SupplementPolicy()->delete(adminGranted(['Delete:Supplement']), $complement))->toBeTrue()
            ->and(new SupplementPolicy()->delete(adminGranted([]), $complement))->toBeFalse()
            ->and(new GoalPolicy()->view(adminGranted(['View:Goal']), $objectif))->toBeTrue()
            ->and(new GoalPolicy()->view(adminGranted([]), $objectif))->toBeFalse()
            ->and(new GoalPolicy()->delete(adminGranted(['Delete:Goal']), $objectif))->toBeTrue()
            ->and(new GoalPolicy()->delete(adminGranted([]), $objectif))->toBeFalse();
    });

    /**
     * La suppression etait la seule ability de Workout et d'Exercise dont la
     * branche administrateur n'etait jamais atteinte : leurs jumelles `view` et
     * `update` l'etaient deja, juste au-dessus.
     */
    it('separe aussi la suppression d\'une seance et d\'un exercice', function (): void {
        $proprietaire = User::factory()->create(['id' => IDENTIFIANT_HORS_DE_PORTEE]);
        $seance = Workout::factory()->create(['user_id' => $proprietaire->id]);
        $exercice = Exercise::factory()->create(['user_id' => $proprietaire->id]);

        expect(new WorkoutPolicy()->delete(adminGranted(['Delete:Workout']), $seance))->toBeTrue()
            ->and(new WorkoutPolicy()->delete(adminGranted([]), $seance))->toBeFalse()
            ->and(new ExercisePolicy()->delete(adminGranted(['Delete:Exercise']), $exercice))->toBeTrue()
            ->and(new ExercisePolicy()->delete(adminGranted([]), $exercice))->toBeFalse();
    });

    it('ne laisse pas un administrateur lire les trophees sans la permission', function (): void {
        expect(new AchievementPolicy()->view(adminGranted(['View:Achievement'])))->toBeTrue()
            ->and(new AchievementPolicy()->view(adminGranted([])))->toBeFalse();
    });

    /**
     * L'inverse, et c'est une frontiere de securite : sur les trophees, la garde
     * ne donne pas un droit, elle en REFUSE un. Un utilisateur de l'application
     * n'ecrit jamais un trophee, meme si une autorisation le lui accorde par
     * ailleurs.
     *
     * L'autorisation est definie ici expres. Sans elle, les deux branches
     * repondent NON — l'une par la garde, l'autre parce qu'aucune autorisation
     * de ce nom n'existe — et le test passe aussi bien avec la garde qu'avec un
     * `if (false)` a la place. C'est exactement ce qui laissait quatre mutants
     * survivre ici.
     */
    it('refuse a un utilisateur d\'ecrire un trophee, meme autorise par ailleurs', function (): void {
        Gate::define('Update:Achievement', fn (): bool => true);
        Gate::define('Delete:Achievement', fn (): bool => true);

        $utilisateur = User::factory()->create();
        $policy = new AchievementPolicy();

        expect($policy->update($utilisateur))->toBeFalse()
            ->and($policy->delete($utilisateur))->toBeFalse();
    });
});

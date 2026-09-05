<?php

declare(strict_types=1);

use App\Models\Achievement;
use App\Models\User;
use App\Models\Workout;
use App\Services\AchievementService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * Debloquer dix succes d'un coup ne lit pas dix fois les preferences.
 *
 * `AchievementUnlocked::via()` demande a l'utilisateur si la poussee est
 * activee pour les trophees, et `via()` est appele EN SYNCHRONE a la mise en
 * file, meme pour une notification `ShouldQueue`. `User::isPushEnabled()` sert
 * la relation quand elle est chargee et fait un `exists()` sinon : sans le
 * chargement prealable, c'est une requete par succes debloque.
 *
 * Ce fichier ne simule PAS les notifications, contrairement a
 * `AchievementServiceTest` : sous `Notification::fake()`, `via()` n'est jamais
 * appele et la lecture qu'on mesure ici n'a pas lieu.
 */
it('ne relit pas les préférences pour chaque succès débloqué', function (): void {
    /*
     * L'enregistrement d'une seance met deja les succes en file. Sans cette
     * mise en attente, tout serait debloque avant l'appel qu'on mesure, et il
     * n'aurait plus rien a faire. La mise en file ne masque pas la lecture
     * mesuree : `via()` est appele avant la mise en file, pas dans le travail.
     */
    Queue::fake();

    $user = User::factory()->create();

    foreach ([1, 2, 3] as $seuil) {
        Achievement::factory()->create([
            'type' => 'count',
            'threshold' => $seuil,
            'slug' => "seances-{$seuil}",
        ]);
    }

    Workout::factory()->count(3)->create(['user_id' => $user->id]);

    /** @var list<string> $sql */
    $sql = [];

    DB::listen(function (QueryExecuted $requete) use (&$sql): void {
        $sql[] = $requete->sql;
    });

    app(AchievementService::class)->syncAchievements($user);

    DB::getEventDispatcher()->forget(QueryExecuted::class);

    $lectures = array_filter($sql, fn (string $requete): bool => str_contains($requete, '`notification_preferences`'));

    expect($user->achievements()->count())->toBe(3)
        ->and($lectures)->toHaveCount(1);
});

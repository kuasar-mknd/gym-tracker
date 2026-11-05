<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\AchievementUnlocked;
use App\Services\NotificationService;
use App\Services\StreakService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Refait la série de jours de chaque compte depuis ses seules séances faites,
 * et retire les succès de série qu'elles ne soutiennent pas, avec l'annonce
 * de chacun, sans rien annoncer (#2171).
 *
 * La série de jours (`users.current_streak`, `longest_streak` et
 * `last_workout_at`) et les succès de type `streak` (« Série de 3 »)
 * comptaient toute séance du compte, dès son démarrage : trois séances
 * démarrées puis terminées sans rien soulever, trois jours de suite, faisaient
 * une série de trois jours et débloquaient « Série de 3 ». Ils ne comptent
 * plus que les séances faites, terminées avec une série cochée
 * (`User::seancesFaites()`), comme les succès de séances et les objectifs de
 * fréquence (#2110).
 *
 * La série stockée ne se corrigerait qu'à la prochaine séance faite ou
 * supprimée du compte ; d'ici là, l'accueil afficherait une série qu'aucune
 * séance faite ne soutient, et le contrôle nocturne la signalerait. Elle est
 * donc refaite ici, par l'écrivain (`StreakService::recalculerDepuisLesFaits()`),
 * pour tout compte qui a une séance, une série stockée ou un succès de série :
 * une seconde écriture de la série serait un écrivain de plus
 * (`.ai/rules/models.md`). Une série qui ne change pas ne s'écrit pas.
 *
 * Le service ne retire jamais un succès : sans cette migration, une « Série
 * de 3 » que des séances vides ont débloquée resterait affichée pour de bon,
 * avec sa description (« Tu as travaillé 3 jours consécutifs »). Un succès de
 * série reste quand la plus longue série refaite atteint son seuil, comme le
 * recalcul le débloquerait (le succès lit les mêmes jours,
 * `AchievementService::getUniqueWorkoutDates()`) ; sa date d'obtention ne
 * change pas. Les autres partent, et avec eux leur notification, lue ou non :
 * la modale de célébration montre la dernière non lue. Les deux entrées de
 * cache des notifications du compte s'oublient avec elles. Un succès retiré
 * revient, annoncé, à la séance faite qui atteint son seuil. Rien ne
 * distingue un succès débloqué par des séances vides d'un succès mérité dont
 * les séances faites ont été supprimées depuis : le second part aussi, et
 * revient de la même façon.
 *
 * Rejouable : un second passage ne trouve plus rien à écrire ni à retirer.
 */
return new class() extends Migration
{
    public function up(): void
    {
        /** @var array<int, float> $seuils */
        $seuils = DB::table('achievements')
            ->where('type', 'streak')
            ->pluck('threshold', 'id')
            ->map(fn (mixed $seuil): float => is_numeric($seuil) ? (float) $seuil : 0.0)
            ->all();

        $series = app(StreakService::class);

        User::query()
            ->where(function (Builder $comptes) use ($seuils): void {
                $comptes->whereNotNull('last_workout_at')
                    ->orWhere('current_streak', '>', 0)
                    ->orWhere('longest_streak', '>', 0)
                    ->orWhereHas('workouts')
                    ->orWhereIn('id', DB::table('user_achievements')->select('user_id')->whereIn('achievement_id', array_keys($seuils)));
            })
            ->chunkById(200, function (Collection $comptes) use ($series, $seuils): void {
                foreach ($comptes as $compte) {
                    if ($compte instanceof User) {
                        $series->recalculerDepuisLesFaits($compte);
                        $this->retirerCeQueRienNeSoutient($compte, $seuils);
                    }
                }
            });
    }

    /**
     * Volontairement vide : la série d'avant comptait des séances vides ou en
     * cours, et rendre ces succès les afficherait de nouveau sans séance faite
     * pour les soutenir. Rien ne garde, de toute façon, la série d'avant.
     */
    public function down(): void
    {
    }

    /**
     * Retire du compte les succès de série dont le seuil dépasse sa plus
     * longue série refaite, et leurs notifications.
     *
     * @param  array<int, float>  $seuils  Le seuil de chaque succès de série, par identifiant.
     */
    private function retirerCeQueRienNeSoutient(User $compte, array $seuils): void
    {
        $sansSoutien = array_keys(array_filter($seuils, fn (float $seuil): bool => $seuil > $compte->longest_streak));

        if ($sansSoutien === []) {
            return;
        }

        $retires = DB::table('user_achievements')
            ->where('user_id', $compte->id)
            ->whereIn('achievement_id', $sansSoutien)
            ->pluck('achievement_id')
            ->all();

        if ($retires === []) {
            return;
        }

        DB::table('user_achievements')
            ->where('user_id', $compte->id)
            ->whereIn('achievement_id', $retires)
            ->delete();

        $compte->notifications()
            ->where('type', AchievementUnlocked::class)
            ->whereIn('data->achievement_id', $retires)
            ->delete();

        app(NotificationService::class)->clearCache($compte);
    }
};

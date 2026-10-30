<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\AchievementUnlocked;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retire les succès de séances que les séances faites du compte ne
 * soutiennent pas, avec l'annonce de chacun (#2110).
 *
 * Les succès de type `count` (« Première Séance », « Guerrier de la
 * Semaine », « Roi de la Constance ») comptaient toutes les séances du
 * compte : démarrer une séance débloquait « Première Séance » avant toute
 * série, et trois séances terminées sans rien soulever, « Guerrier de la
 * Semaine ». Ils ne comptent plus que les séances faites, terminées avec
 * une série cochée (`User::seancesFaites()`). Mais le service ne retire
 * jamais un succès : sans cette migration, ceux que le défaut a débloqués
 * resteraient affichés pour de bon, avec leur description (« Tu as complété
 * ta toute première séance »), sans que rien ne les soutienne.
 *
 * Un succès de séances reste quand le compte a aujourd'hui au moins autant
 * de séances faites que son seuil, comme le recalcul le débloquerait ; sa
 * date d'obtention ne change pas. Les autres partent, et avec eux leur
 * notification, lue ou non : la modale de célébration montre la dernière
 * non lue, et elle aurait fêté un succès retiré. Les deux entrées de cache
 * des notifications du compte s'oublient avec elles. Un succès retiré revient,
 * annoncé, à la séance faite qui atteint son seuil.
 *
 * Le texte ne permet pas de distinguer un succès débloqué par des séances
 * vides d'un succès mérité dont les séances ont été supprimées depuis : le
 * second part aussi, et revient de la même façon. Les objectifs de fréquence
 * ne sont pas touchés ici : `GoalService::syncGoals()` les recalcule tous, à
 * la baisse comme à la hausse, à la prochaine séance, série ou mesure du
 * compte (#2132 a fait le même choix).
 *
 * Rejouable : un second passage ne trouve plus rien à retirer.
 */
return new class() extends Migration
{
    public function up(): void
    {
        /** @var array<int, float> $seuils */
        $seuils = DB::table('achievements')
            ->where('type', 'count')
            ->pluck('threshold', 'id')
            ->map(fn (mixed $seuil): float => is_numeric($seuil) ? (float) $seuil : 0.0)
            ->all();

        if ($seuils === []) {
            return;
        }

        User::query()
            ->whereIn('id', DB::table('user_achievements')->select('user_id')->whereIn('achievement_id', array_keys($seuils)))
            ->chunkById(200, function (Collection $comptes) use ($seuils): void {
                foreach ($comptes as $compte) {
                    if ($compte instanceof User) {
                        $this->retirerCeQueRienNeSoutient($compte, $seuils);
                    }
                }
            });
    }

    /**
     * Volontairement vide : rendre ces succès les afficherait de nouveau sans
     * séance faite pour les soutenir.
     */
    public function down(): void
    {
    }

    /**
     * Retire du compte les succès de séances dont le seuil dépasse ses
     * séances faites, et leurs notifications.
     *
     * @param  array<int, float>  $seuils  Le seuil de chaque succès de séances, par identifiant.
     */
    private function retirerCeQueRienNeSoutient(User $compte, array $seuils): void
    {
        $faites = $compte->seancesFaites()->count();

        $sansSoutien = array_keys(array_filter($seuils, fn (float $seuil): bool => $seuil > $faites));

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

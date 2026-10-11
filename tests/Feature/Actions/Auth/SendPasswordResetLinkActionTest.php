<?php

declare(strict_types=1);

/*
 * `SendPasswordResetLinkAction` etait traversee par les tests d'authentification
 * sans qu'aucun ne regarde ce qu'elle decide : ses quatre mutants survivaient
 * tous les quatre.
 *
 * Elle ne decide plus rien (#1951) : elle met en file l'envoi du lien, sans
 * chercher le compte, pour que ni la reponse ni le temps de la requete ne
 * disent si l'adresse en a un. Une adresse inconnue levait une erreur de
 * validation nommant le champ ; elle ne leve plus rien, et rien ne part.
 *
 * Les tests ci-dessous tiennent ce qu'elle met en file — sans l'envoi, ou avec
 * une autre adresse ou une autre langue, le lien ne partirait pas, ou partirait
 * ailleurs, ou dans une autre langue que celle de la page —, puis ce que le job
 * en fait.
 */

use App\Actions\Auth\SendPasswordResetLinkAction;
use App\Jobs\EnvoyerLeLienDeReinitialisation;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

it('met en file l envoi du lien, avec l adresse et la langue de la demande', function (): void {
    Queue::fake();
    app()->setLocale('en');

    app(SendPasswordResetLinkAction::class)->execute('lien@example.test');

    Queue::assertPushedTimes(EnvoyerLeLienDeReinitialisation::class, 1);
    Queue::assertPushed(
        EnvoyerLeLienDeReinitialisation::class,
        static fn (EnvoyerLeLienDeReinitialisation $job): bool => $job->adresse === 'lien@example.test' && $job->langue === 'en',
    );
});

it('envoie le lien au compte de l adresse, une fois le job traite', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email' => 'lien@example.test']);

    app(SendPasswordResetLinkAction::class)->execute($user->email);

    Notification::assertSentToTimes($user, ResetPassword::class, 1);
});

it('ne leve plus d erreur de validation pour une adresse inconnue, et n envoie rien', function (): void {
    Notification::fake();

    // Aucun utilisateur ne porte cette adresse : le courtier rend `passwords.user`
    // dans le job, et ce statut ne sort pas du job.
    app(SendPasswordResetLinkAction::class)->execute('inconnue@example.test');

    Notification::assertNothingSent();
});

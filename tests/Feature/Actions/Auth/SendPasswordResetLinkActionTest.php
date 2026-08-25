<?php

declare(strict_types=1);

/*
 * `SendPasswordResetLinkAction` etait traversee par les tests d'authentification
 * sans qu'aucun ne regarde ce qu'elle decide : ses quatre mutants survivaient
 * tous les quatre.
 *
 * Concretement, chacune de ces reecritures passait la suite au vert :
 *
 *  - inverser le test de statut, c'est-a-dire rendre le statut quand l'envoi a
 *    ECHOUE et lever une erreur de validation quand il a REUSSI. Une adresse
 *    inconnue aurait alors affiche « lien envoye » a la page, et une adresse
 *    valide un message d'erreur ;
 *  - vider le message porte par l'erreur, laissant le formulaire signaler un
 *    champ fautif sans dire pourquoi ;
 *  - vider la cle `email` de l'erreur, la detachant du champ : le formulaire
 *    n'aurait plus rien a colorer ni a annoncer.
 *
 * Les deux tests ci-dessous couvrent les deux sorties de la ligne 25 — sans le
 * cas qui reussit, inverser la comparaison resterait invisible.
 */

use App\Actions\Auth\SendPasswordResetLinkAction;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

it('rend le statut d envoi quand le lien part', function (): void {
    Notification::fake();

    $user = User::factory()->create(['email' => 'lien@example.test']);

    $statut = app(SendPasswordResetLinkAction::class)->execute(['email' => $user->email]);

    // Le statut lui-meme, et non « quelque chose de non vide » : le controleur
    // le passe a `__()` pour composer le message de la page.
    expect($statut)->toBe(Password::RESET_LINK_SENT);
});

it('leve une erreur de validation nommant le champ email quand l envoi echoue', function (): void {
    Notification::fake();

    // Aucun utilisateur ne porte cette adresse : le courtier rend
    // `passwords.user`, pas `passwords.sent`.
    try {
        app(SendPasswordResetLinkAction::class)->execute(['email' => 'inconnue@example.test']);

        $this->fail('Une adresse inconnue devrait lever une ValidationException.');
    } catch (ValidationException $validationException) {
        // La forme exacte de l'erreur : la cle `email` — que le formulaire lit
        // pour designer le champ — et le message traduit qu'elle porte. Un
        // tableau vide, cote cle comme cote message, laisserait la page muette.
        expect($validationException->errors())->toBe([
            'email' => ['Nous ne trouvons aucun utilisateur avec cette adresse email.'],
        ]);
    }

    Notification::assertNothingSent();
});

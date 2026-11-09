<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
 * `Show.vue:335` décide de la durée du repos ainsi :
 *
 *     exerciseRestTime || auth.user.default_rest_time || 90
 *
 * Le champ n'était pas envoyé au client : la lecture rendait `undefined`, le
 * repli à 90 s'appliquait toujours, et le réglage stocké n'avait aucun effet.
 *
 * Le défaut était invisible tant qu'on ne changeait pas la valeur — la colonne
 * vaut 90 par défaut en base, soit exactement le repli.
 */
it('envoie au client le temps de repos que l’utilisateur a choisi', function (): void {
    $user = User::factory()->create(['default_rest_time' => 150]);

    $requete = Request::create('/dashboard');
    $requete->setUserResolver(fn (): User => $user);

    $partages = new HandleInertiaRequests()->share($requete);

    expect(data_get($partages, 'auth.user.default_rest_time'))->toBe(150);
});

it('envoie la valeur stockée, quelle qu’elle soit', function (): void {
    /*
     * Sans affirmer un chiffre : écrire « 90 » ici aurait fait un test qui
     * décrit la fabrique et non le contrat — le contrat, c'est que le client
     * lise ce qui est stocké, sans que le middleware s'en mêle.
     */
    $user = User::factory()->create();

    $requete = Request::create('/dashboard');
    $requete->setUserResolver(fn (): User => $user);

    $partages = new HandleInertiaRequests()->share($requete);

    /*
     * La CLÉ, et pas seulement la valeur : `data_get` rend `null` aussi bien
     * pour un champ absent que pour un champ nul. Comparer les deux `null`
     * aurait fait un test qui passe alors que le champ ne part pas.
     */
    expect(data_get($partages, 'auth.user'))->toHaveKey('default_rest_time')
        ->and(data_get($partages, 'auth.user.default_rest_time'))->toBe($user->default_rest_time);
});

/*
 * La fabrique posait 60 s quand la colonne vaut 90 par défaut (relevé dans
 * #1514, réglé par #1513) : un compte de test n'était pas un compte ordinaire,
 * et un test qui lisait le repos d'un compte sans le poser décrivait la
 * fabrique, pas l'application.
 */
it('pose dans la fabrique des comptes le temps de repos par défaut de la colonne', function (): void {
    $defaut = DB::scalar(
        "select column_default from information_schema.columns where table_schema = database() and table_name = 'users' and column_name = 'default_rest_time'",
    );

    expect(is_numeric($defaut) ? (int) $defaut : null)->toBe(90)
        ->and(User::factory()->make()->default_rest_time)->toBe(90);
});

<?php

declare(strict_types=1);

use App\Http\Requests\Auth\NewPasswordRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\Password;
use Tests\Support\ReglesDesRequetes;

/*
 * Un champ validé sans règle qui fixe sa forme (`string`, `integer`,
 * `array`…) laisse passer un tableau, un nombre ou une chaîne de toute
 * longueur jusqu'au code qui le lit (#2071) : les clés d'un abonnement push,
 * validées par `required` seul, allaient en tableau ou en nombre au paquet
 * WebPush, qui les attend en chaînes, et la requête répondait 500.
 *
 * La garde des écritures (`UneRequeteMalFormeeNeRepondPas500Test`) ne
 * pouvait pas le voir : elle envoie tous les champs mal formés à la fois, et
 * l'adresse de l'abonnement, refusée en même temps, arrêtait la requête avant
 * le contrôleur. Celle-ci lit donc les règles, telles que Laravel les
 * applique (`ReglesDesRequetes`) : chaque champ de `app/Http/Requests` porte
 * une règle de forme, ou un objet de règle qui refuse lui-même ce qui n'est
 * pas une chaîne (`Password`). Un champ que rien ne lit hors de la validation,
 * ou que son lecteur lit dans sa forme, entre dans `formesExceptions()` avec
 * sa raison.
 */

/**
 * Les règles de Laravel qui refusent toute valeur hors de leur forme : un
 * tableau n'est admis que par `array` et `list`, un nombre ou une chaîne ne
 * passe qu'à la règle qui les attend, et `exclude`, `missing` ou `prohibited`
 * retirent ou refusent le champ.
 *
 * @return list<string>
 */
function formesReglesQuiFixentLaForme(): array
{
    return [
        'accepted', 'active_url', 'alpha', 'alpha_dash', 'alpha_num', 'array', 'boolean', 'bool',
        'current_password', 'date', 'date_format', 'decimal', 'declined', 'digits', 'digits_between',
        'email', 'exclude', 'file', 'hex_color', 'image', 'in', 'int', 'integer', 'ip', 'ipv4', 'ipv6',
        'json', 'list', 'mac_address', 'mimes', 'mimetypes', 'missing', 'numeric', 'prohibited',
        'regex', 'string', 'timezone', 'ulid', 'url', 'uuid',
    ];
}

/**
 * Les champs sans règle de forme, avec la raison.
 *
 * @return array<class-string<FormRequest>, array<string, string>>
 */
function formesExceptions(): array
{
    return [
        NewPasswordRequest::class => [
            'adresse' => 'lu par `LiensDesCourriels::adresseDuLienDeReinitialisation()`, qui n’admet qu’une chaîne : toute autre forme reçoit le refus d’un jeton faux, sans règle qui en juge (`.ai/rules/controllers.md`)',
            'password_confirmation' => 'comparé par `confirmed` à `password`, que `Password` exige en chaîne : il ne passe la validation qu’identique à lui',
        ],
    ];
}

/**
 * La règle de forme des règles d'un champ, ou null s'il n'en a pas.
 *
 * @param  list<string|object>  $regles
 */
function formesRegleDeForme(array $regles): ?string
{
    foreach (ReglesDesRequetes::textes($regles) as $regle) {
        $nom = strtolower(explode(':', $regle, 2)[0]);

        if (in_array($nom, formesReglesQuiFixentLaForme(), true)) {
            return $nom;
        }
    }

    foreach ($regles as $regle) {
        if ($regle instanceof Password || $regle instanceof Enum || $regle instanceof File) {
            return $regle::class;
        }
    }

    return null;
}

it('fixe la forme de chaque champ validé', function (): void {
    $exceptions = formesExceptions();
    $fautes = [];

    foreach (array_keys(ReglesDesRequetes::toutes()) as $requete) {
        foreach (ReglesDesRequetes::de($requete) as $champ => $regles) {
            if (! isset($exceptions[$requete][$champ]) && formesRegleDeForme($regles) === null) {
                $fautes[] = "{$requete} : {$champ} (".implode('|', ReglesDesRequetes::textes($regles)).')';
            }
        }
    }

    expect($fautes)->toBe([], "champs sans règle de forme :\n  ".implode("\n  ", $fautes));
});

it('n’excepte que des champs qui existent et n’ont pas de règle de forme', function (): void {
    $perimees = [];

    foreach (formesExceptions() as $requete => $champs) {
        $regles = ReglesDesRequetes::de($requete);

        foreach (array_keys($champs) as $champ) {
            if (! isset($regles[$champ]) || formesRegleDeForme($regles[$champ]) !== null) {
                $perimees[] = "{$requete} : {$champ}";
            }
        }
    }

    expect($perimees)->toBe([], "exceptions sans objet :\n  ".implode("\n  ", $perimees));
});

/*
 * La garde voit le défaut d'origine : sans quoi elle ne prouverait rien.
 */
it('refuse les clés d’origine d’un abonnement push, et accepte les formes typées', function (): void {
    expect(formesRegleDeForme(['required']))->toBeNull()
        ->and(formesRegleDeForme(['nullable', 'max:255']))->toBeNull()
        ->and(formesRegleDeForme(['bail', 'required', 'string', 'max:255']))->toBe('string')
        ->and(formesRegleDeForme(['required', 'in:a,b']))->toBe('in')
        ->and(formesRegleDeForme(['required', 'confirmed', Password::defaults()]))->toBe(Password::class);
});

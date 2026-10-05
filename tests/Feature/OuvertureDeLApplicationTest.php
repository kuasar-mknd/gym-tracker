<?php

declare(strict_types=1);

use App\Models\User;

/*
 * Ce qu'ouvre l'application installée (#1969).
 *
 * Sans `id`, le navigateur déduit l'identité de l'application de `start_url`.
 * Changer l'adresse de départ sans la fixer d'abord en ferait une autre
 * application à ses yeux, et les PWA déjà installées ignoreraient la mise à
 * jour du manifeste. L'identité reste donc celle qu'elles ont toujours eue,
 * « / », l'ancienne adresse de départ.
 *
 * L'adresse de départ, elle, était « / », qui ne fait que rediriger vers le
 * tableau de bord : chaque ouverture payait un aller-retour de plus avant la
 * vraie page, et deux pour un visiteur déconnecté. Elle mène désormais au
 * tableau de bord, comme le rappel d'entraînement.
 */

/**
 * Le manifeste de l'application, décodé.
 *
 * @return array<string, mixed>
 */
function ouvertureManifeste(): array
{
    $manifeste = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true);

    expect($manifeste)->toBeArray();

    /** @var array<string, mixed> $manifeste */
    return $manifeste;
}

/**
 * Une clé du manifeste qui doit porter une adresse.
 *
 * @return non-empty-string
 */
function ouvertureAdresseDuManifeste(string $cle): string
{
    $adresse = ouvertureManifeste()[$cle] ?? null;

    if (! is_string($adresse) || $adresse === '') {
        throw new UnexpectedValueException("Le manifeste ne déclare pas « {$cle} ».");
    }

    return $adresse;
}

/**
 * L'adresse de départ que déclare le manifeste.
 */
function ouvertureAdresseDeDepart(): string
{
    return ouvertureAdresseDuManifeste('start_url');
}

it('garde aux applications installées l’identité qu’elles ont toujours eue', function (): void {
    expect(ouvertureManifeste())->toHaveKey('id', '/');
});

it('ouvre le tableau de bord, dans la portée de l’application', function (): void {
    expect(ouvertureAdresseDeDepart())->toBe(route('dashboard', absolute: false))
        ->and(ouvertureAdresseDeDepart())->toStartWith(ouvertureAdresseDuManifeste('scope'));
});

it('sert la page de départ sans redirection à un utilisateur connecté', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(ouvertureAdresseDeDepart())
        ->assertOk();
});

it('mène un visiteur déconnecté à la connexion en une seule redirection', function (): void {
    $this->get(ouvertureAdresseDeDepart())
        ->assertRedirect(route('login'));
});

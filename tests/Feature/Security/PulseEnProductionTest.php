<?php

declare(strict_types=1);

use App\Models\Admin;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\WorkerOctane;

/**
 * Pulse tel que la production le sert : `APP_ENV=production`, une liste
 * d'adresses admises, Pulse coupé, et un worker Octane démarré une fois.
 *
 * `/backoffice/pulse` répondait 403 en production à tout le monde, super
 * administrateur compris, et son lien restait caché : la porte `viewPulse`
 * était celle de Pulse, ouverte au seul environnement `local`. La suite ne le
 * voyait pas, parce qu'une porte de test, toujours vraie, la remplaçait en
 * `testing`. On démarre donc ici une application neuve, en production.
 */

/**
 * @param  list<Request>  $requetes
 * @param  (Closure(Application): mixed)|null  $avantDeServir
 * @return array{environnement: string, statuts: list<int>, reponses: list<Response>, erreurs: list<string>, avant: mixed}
 */
function pulseProductionServir(string $environnement, ?Admin $administrateur, array $requetes, ?Closure $avantDeServir = null): array
{
    return WorkerOctane::servir([
        'APP_ENV' => $environnement,
        'APP_DEBUG' => 'false',
        'ADMIN_ALLOWED_IPS' => '127.0.0.1',
        'PULSE_ENABLED' => 'false',
    ], $requetes, $administrateur, $avantDeServir);
}

function pulseProductionSuperAdministrateur(): Admin
{
    $superAdministrateur = Admin::factory()->create();
    $superAdministrateur->assignRole(Role::findOrCreate('super_admin', 'admin'));

    return $superAdministrateur->fresh() ?? $superAdministrateur;
}

function pulseProductionAdministrateurSansOutils(): Admin
{
    $administrateur = Admin::factory()->create();
    $administrateur->assignRole(Role::findOrCreate('invite', 'admin'));

    return $administrateur->fresh() ?? $administrateur;
}

/**
 * La porte et le lien du menu, tels que l'application de base les voit pour
 * cet administrateur.
 *
 * @return Closure(Application): array{porte: bool, lienVisible: bool|null}
 */
function pulseProductionPorteEtLien(Admin $administrateur): Closure
{
    return static function (Application $base) use ($administrateur): array {
        $base->make('auth')->guard('admin')->setUser($administrateur);
        $lien = collect(Filament::getPanel('admin')->getNavigationItems())
            ->first(static fn (NavigationItem $element): bool => $element->getLabel() === 'Pulse Serveur');

        return [
            'porte' => $base->make(Gate::class)->forUser($administrateur)->allows('viewPulse'),
            'lienVisible' => $lien?->isVisible(),
        ];
    };
}

/**
 * Le nonce de l'en-tête, ceux que portent les balises du corps, et le nombre
 * de balises en ligne sans nonce (contenu des scripts retiré : livewire.js
 * contient lui-même le texte `<script>`).
 *
 * @return array{entete: string|null, corps: list<string>, nonSignes: int}
 */
function pulseProductionNonces(Response $reponse): array
{
    preg_match("/'nonce-([^']+)'/", (string) $reponse->headers->get('Content-Security-Policy'), $entete);
    $balises = (string) preg_replace('#(<(script|style)\b[^>]*>).*?</\2>#is', '$1</$2>', (string) $reponse->getContent());
    preg_match_all('/\snonce="([^"]+)"/', $balises, $corps);

    return [
        'entete' => $entete[1] ?? null,
        'corps' => array_values(array_unique($corps[1])),
        'nonSignes' => (int) preg_match_all('/<(?:script|style)\b(?![^>]*\b(?:src|nonce)=)[^>]*>/i', $balises),
    ];
}

it('ouvre Pulse au super administrateur en production, à chaque requête du même worker, sous un nonce neuf', function (): void {
    $resultat = pulseProductionServir('production', pulseProductionSuperAdministrateur(), [
        Request::create('/backoffice/pulse'),
        Request::create('/backoffice/pulse'),
    ]);

    expect($resultat['environnement'])->toBe('production')
        ->and($resultat['erreurs'])->toBe([])
        ->and($resultat['statuts'])->toBe([200, 200]);

    [$premiere, $seconde] = array_map(pulseProductionNonces(...), $resultat['reponses']);

    expect($premiere['entete'])->toBeString()
        ->and($premiere['corps'])->toBe([$premiere['entete']])
        ->and($seconde['corps'])->toBe([$seconde['entete']])
        ->and($premiere['nonSignes'] + $seconde['nonSignes'])->toBe(0)
        ->and($premiere['entete'])->not->toBe($seconde['entete']);
});

it('garde Pulse fermé en production à un administrateur sans les outils', function (): void {
    $resultat = pulseProductionServir('production', pulseProductionAdministrateurSansOutils(), [
        Request::create('/backoffice/pulse'),
    ]);

    expect($resultat['statuts'])->toBe([403]);
});

it('montre le lien « Pulse Serveur » du panneau au super administrateur en production', function (): void {
    $superAdministrateur = pulseProductionSuperAdministrateur();

    $resultat = pulseProductionServir('production', $superAdministrateur, [Request::create('/backoffice')], pulseProductionPorteEtLien($superAdministrateur));

    expect($resultat['avant'])->toBe(['porte' => true, 'lienVisible' => true])
        ->and($resultat['statuts'])->toBe([200])
        ->and((string) $resultat['reponses'][0]->getContent())->toContain('Pulse Serveur');
});

it('cache le lien « Pulse Serveur » à un administrateur sans les outils', function (): void {
    $administrateur = pulseProductionAdministrateurSansOutils();

    $resultat = pulseProductionServir('production', $administrateur, [], pulseProductionPorteEtLien($administrateur));

    expect($resultat['avant'])->toBe(['porte' => false, 'lienVisible' => false]);
});

/**
 * Pulse pose sa propre porte par `callAfterResolving(Gate::class)`, ouverte au
 * seul environnement `local`, invité compris. Démarrée en local, elle
 * laisserait entrer n'importe qui : la nôtre, définie après, doit l'emporter.
 */
it('garde notre porte viewPulse, et non celle de Pulse, même démarré en local', function (): void {
    $superAdministrateur = pulseProductionSuperAdministrateur();
    $autre = pulseProductionAdministrateurSansOutils();

    $resultat = pulseProductionServir('local', null, [], static fn (Application $base): array => [
        'superAdministrateur' => $base->make(Gate::class)->forUser($superAdministrateur)->allows('viewPulse'),
        'autre' => $base->make(Gate::class)->forUser($autre)->allows('viewPulse'),
        'invite' => $base->make(Gate::class)->allows('viewPulse'),
    ]);

    expect($resultat['environnement'])->toBe('local')
        ->and($resultat['avant'])->toBe(['superAdministrateur' => true, 'autre' => false, 'invite' => false]);
});

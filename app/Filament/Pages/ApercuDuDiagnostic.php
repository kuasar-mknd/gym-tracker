<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\PorteeDeDiagnostic;
use App\Services\Diagnostic\ApercuDesDetails;
use App\Services\Diagnostic\ChoixDeLApercu;
use App\Services\Diagnostic\EnveloppeRendue;
use App\Services\Diagnostic\FenetreDeDiagnostic;
use App\Services\Diagnostic\ResumeDuDiagnostic;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;

/**
 * « Système › Aperçu du diagnostic » (#2032) : pour les portées choisies,
 * exactement le JSON que l'agent de maintenance lirait, et le nombre de
 * caractères invisibles retirés pour l'écrire. Rien ne sort de la machine :
 * la page montre, elle n'ouvre aucun accès.
 *
 * Le résumé ne rend que de la structure. Le détail du premier élément de
 * chaque liste rend, sous la portée `TexteBrut` seulement, le texte libre
 * expurgé, sous `non_fiable`. « Copier la structure » copie le résumé et les
 * détails sans aucune clé `non_fiable` : un résumé collé dans une
 * conversation reste une donnée, jamais une consigne.
 *
 * Le choix des portées passe par la chaîne de requête (`ChoixDeLApercu`) :
 * la page ne garde aucun état Livewire. Elle est réservée à qui a la
 * capacité `view-diagnostic`, que
 * `AppServiceProvider::ouvrirLesOutilsAuSuperAdministrateur()` ouvre au super
 * administrateur ; Filament la revérifie au montage et à chaque mise à jour
 * du composant.
 */
final class ApercuDuDiagnostic extends Page
{
    public const string CAPACITE = 'view-diagnostic';

    /** @var array<int, string> */
    private const array FENETRES = [1 => '24 heures', 7 => '7 jours', 30 => '30 jours'];

    /** @var array<string, string> */
    private const array LIBELLES = [
        'sante' => 'Santé, tâches planifiées et versions',
        'exceptions' => 'Exceptions',
        'files' => 'Files et jobs échoués',
        'erreurs-navigateur' => 'Erreurs navigateur',
        'texte-brut' => 'Texte brut expurgé, au détail',
    ];

    #[\Override]
    protected static ?string $slug = 'apercu-du-diagnostic';

    #[\Override]
    protected static ?string $title = 'Aperçu du diagnostic';

    #[\Override]
    protected static ?string $navigationLabel = 'Aperçu du diagnostic';

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    #[\Override]
    protected static \UnitEnum|string|null $navigationGroup = 'Système';

    #[\Override]
    protected static ?int $navigationSort = 94;

    #[\Override]
    protected string $view = 'filament.pages.apercu-du-diagnostic';

    /**
     * L'administrateur du panneau a-t-il la capacité ? Une permission Shield
     * accordée à un autre rôle l'ouvre aussi ; un visiteur, jamais.
     */
    #[\Override]
    public static function canAccess(): bool
    {
        return Gate::forUser(auth('admin')->user())->allows(self::CAPACITE);
    }

    /**
     * @return array{resume: string, details: string, invisibles: int, structure: string, portees: list<string>, jours: int, libelles: array<string, string>, fenetres: array<int, string>}
     */
    #[\Override]
    protected function getViewData(): array
    {
        $choix = ChoixDeLApercu::de(request()->query());
        $fenetre = FenetreDeDiagnostic::jours($choix->jours, Date::now());
        $resume = new ResumeDuDiagnostic()($choix->portees, $fenetre);
        $details = new ApercuDesDetails()($choix->portees, $fenetre, $resume->enveloppe['donnees']);

        return [
            'resume' => $resume->json(lisible: true),
            'details' => $details->json(lisible: true),
            'invisibles' => $resume->invisiblesRetires + $details->invisiblesRetires,
            'structure' => $this->structure($resume, $details),
            'portees' => array_map(static fn (PorteeDeDiagnostic $portee): string => $portee->value, $choix->portees),
            'jours' => $choix->jours,
            'libelles' => self::LIBELLES,
            'fenetres' => self::FENETRES,
        ];
    }

    /**
     * Ce que copie « Copier la structure » : le résumé et les détails, sans
     * aucune clé `non_fiable`.
     */
    private function structure(EnveloppeRendue $resume, EnveloppeRendue $details): string
    {
        return (string) json_encode(
            ['resume' => $resume->structure(), 'details' => $details->structure()],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT,
        );
    }
}

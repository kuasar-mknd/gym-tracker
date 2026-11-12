<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Diagnostic\CreationDeJetonRefusee;
use App\Actions\Diagnostic\CreeUnJetonDeDiagnosticAction;
use App\Actions\Diagnostic\RevoqueLesJetonsDeDiagnosticAction;
use App\Enums\PorteeDeDiagnostic;
use App\Enums\RaisonDeRevocationDuJeton;
use App\Models\Admin;
use App\Models\JetonDeDiagnostic;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

/**
 * « Système › Accès de diagnostic » (#2032) : les jetons de l'agent de
 * maintenance, leur création et leur révocation. La liste ne lit jamais
 * l'empreinte.
 *
 * Le jeton en clair ne vit que le temps d'une fenêtre : il passe de l'action
 * de création à la fenêtre qui l'affiche chiffré par `encrypt()`, n'est
 * déchiffré qu'au rendu de celle-ci, et s'efface de l'état du composant dès
 * qu'elle se ferme. Il n'entre ni dans un champ de formulaire, ni dans la
 * session, ni dans une notification, ni dans un journal.
 *
 * L'accès est réservé au super administrateur dont la double authentification
 * est activée et qui porte la capacité `view-diagnostic`.
 */
final class AccesDeDiagnostic extends Page implements HasTable
{
    use InteractsWithTable;

    /**
     * Ce que l'administrateur lit d'un refus : des phrases écrites ici, jamais
     * une valeur saisie ni le message de l'exception.
     *
     * @var array<string, string>
     */
    private const array REFUS = [
        CreationDeJetonRefusee::INTERRUPTEUR_COUPE => 'Le diagnostic est coupé.',
        CreationDeJetonRefusee::CAPACITE_ABSENTE => 'Ce compte ne peut pas créer de jeton.',
        CreationDeJetonRefusee::DOUBLE_AUTHENTIFICATION_ABSENTE => 'Activez d’abord la double authentification.',
        CreationDeJetonRefusee::CODE_REFUSE => 'Le code est refusé.',
        CreationDeJetonRefusee::TROP_D_ESSAIS => 'Trop d’essais : attendez une minute.',
        CreationDeJetonRefusee::ADRESSE_DES_ALERTES_VIDE => 'Aucune adresse n’est prévue pour les alertes.',
        CreationDeJetonRefusee::JETONS_ACTIFS_AU_MAXIMUM => 'Deux jetons sont déjà actifs : révoquez-en un.',
        CreationDeJetonRefusee::DUREE_INVALIDE => 'Cette durée n’est pas permise pour ces portées.',
        CreationDeJetonRefusee::PORTEES_INVALIDES => 'Ces portées ne sont pas valides.',
    ];

    /** @var array<string, string> */
    private const array LIBELLES_DES_PORTEES = [
        'sante' => 'Santé, tâches planifiées et versions',
        'exceptions' => 'Exceptions',
        'files' => 'Files et jobs échoués',
        'erreurs-navigateur' => 'Erreurs navigateur',
        'texte-brut' => 'Texte brut expurgé, au détail (un jour au plus)',
    ];

    /** @var array<int, string> */
    private const array DUREES = [1 => '1 jour', 7 => '7 jours', 30 => '30 jours'];

    #[\Override]
    protected static ?string $slug = 'acces-de-diagnostic';

    #[\Override]
    protected static ?string $title = 'Accès de diagnostic';

    #[\Override]
    protected static ?string $navigationLabel = 'Accès de diagnostic';

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    #[\Override]
    protected static \UnitEnum|string|null $navigationGroup = 'Système';

    #[\Override]
    protected static ?int $navigationSort = 95;

    #[\Override]
    protected string $view = 'filament.pages.acces-de-diagnostic';

    /** Le jeton en clair, chiffré, le temps de la fenêtre qui l'affiche. */
    #[Locked]
    public ?string $jetonChiffre = null;

    /**
     * Le super administrateur, avec la double authentification et la
     * capacité `view-diagnostic`.
     */
    #[\Override]
    public static function canAccess(): bool
    {
        $administrateur = auth('admin')->user();

        return $administrateur instanceof Admin
            && $administrateur->estSuperAdministrateur()
            && self::secretTotp($administrateur) !== ''
            && Gate::forUser($administrateur)->allows(ApercuDuDiagnostic::CAPACITE);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => JetonDeDiagnostic::query()->select([
                'id', 'identifiant', 'nom', 'portees', 'expire_le', 'revoque_le', 'derniere_utilisation_le',
            ]))
            ->defaultSort('id', 'desc')
            ->paginated(false)
            ->emptyStateHeading('Aucun jeton')
            ->columns([
                TextColumn::make('identifiant')->label('Identifiant')->fontFamily('mono'),
                TextColumn::make('nom')->label('Nom'),
                TextColumn::make('portees')->label('Portées')->badge()
                    ->formatStateUsing(static fn (string $state): string => self::LIBELLES_DES_PORTEES[$state] ?? $state),
                TextColumn::make('expire_le')->label('Expire le')->dateTime('d/m/Y H:i'),
                TextColumn::make('etat')->label('État')->badge()
                    ->state(static fn (JetonDeDiagnostic $jeton): string => self::etatDe($jeton))
                    ->color(static fn (string $state): string => $state === 'actif' ? 'success' : 'gray'),
                TextColumn::make('derniere_utilisation_le')->label('Dernière utilisation')->dateTime('d/m/Y H:i')->placeholder('Jamais'),
            ])
            ->recordActions([
                Action::make('revoquer')
                    ->label('Révoquer')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->authorize(static fn (): bool => self::canAccess())
                    ->visible(static fn (JetonDeDiagnostic $jeton): bool => self::etatDe($jeton) === 'actif')
                    ->action(function (JetonDeDiagnostic $jeton): void {
                        $revoques = resolve(RevoqueLesJetonsDeDiagnosticAction::class)->execute(
                            RaisonDeRevocationDuJeton::Manuelle,
                            self::administrateur(),
                            identifiant: $jeton->identifiant,
                        );

                        Notification::make()->success()->title($revoques === [] ? 'Jeton déjà révoqué' : 'Jeton révoqué')->send();
                    }),
            ]);
    }

    /**
     * @return array<int, Action>
     */
    #[\Override]
    protected function getHeaderActions(): array
    {
        return [$this->creerAction(), $this->toutRevoquerAction()];
    }

    public function creerAction(): Action
    {
        return Action::make('creer')
            ->label('Créer un jeton')
            ->icon(Heroicon::OutlinedPlus)
            ->authorize(static fn (): bool => self::canAccess())
            ->modalHeading('Créer un jeton de diagnostic')
            ->modalSubmitActionLabel('Créer le jeton')
            ->schema([
                TextInput::make('nom')->label('Nom')->required()->maxLength(80),
                CheckboxList::make('portees')
                    ->label('Portées')
                    ->options(self::LIBELLES_DES_PORTEES)
                    ->default(array_map(static fn (PorteeDeDiagnostic $portee): string => $portee->value, PorteeDeDiagnostic::PREMIERES))
                    ->required()
                    ->live()
                    ->afterStateUpdated(static function (Get $get, Set $set): void {
                        if (self::avecTexteBrut($get('portees'))) {
                            $set('jours', JetonDeDiagnostic::DUREE_AVEC_TEXTE_BRUT);
                        }
                    }),
                Select::make('jours')
                    ->label('Durée')
                    ->options(static fn (Get $get): array => self::avecTexteBrut($get('portees'))
                        ? [JetonDeDiagnostic::DUREE_AVEC_TEXTE_BRUT => self::DUREES[JetonDeDiagnostic::DUREE_AVEC_TEXTE_BRUT]]
                        : self::DUREES)
                    ->default(JetonDeDiagnostic::DUREE_PAR_DEFAUT)
                    ->required()
                    ->selectablePlaceholder(false),
                TextInput::make('code')
                    ->label('Code de l’application d’authentification')
                    ->required()
                    ->length(6)
                    ->regex('/^\d{6}$/')
                    ->autocomplete('one-time-code'),
            ])
            ->action(function (array $data, Action $action): void {
                $administrateur = self::administrateur();

                try {
                    $cree = resolve(CreeUnJetonDeDiagnosticAction::class)->execute(
                        $administrateur,
                        self::texte($data['nom'] ?? null),
                        self::portees($data['portees'] ?? null),
                        self::entier($data['jours'] ?? null),
                        self::texte($data['code'] ?? null),
                    );
                } catch (CreationDeJetonRefusee $refus) {
                    $this->refuser(self::REFUS[$refus->motif] ?? 'La création est refusée.');
                    $action->halt();

                    return;
                } catch (LockTimeoutException) {
                    $this->refuser('Une création est déjà en cours. Réessayez.');
                    $action->halt();

                    return;
                }

                $this->jetonChiffre = encrypt($cree['en_clair']);
                unset($cree);

                $this->replaceMountedAction('afficherLeJeton');
            });
    }

    public function afficherLeJetonAction(): Action
    {
        return Action::make('afficherLeJeton')
            ->label('Jeton de diagnostic')
            ->authorize(static fn (): bool => self::canAccess())
            ->modalHeading('Le jeton, affiché une seule fois')
            ->modalWidth('2xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fermer')
            ->closeModalByClickingAway(false)
            ->closeModalByEscaping(false)
            ->modalContent(fn (): View => view('filament.pages.acces-de-diagnostic-jeton', ['jeton' => $this->jetonEnClair()]));
    }

    public function toutRevoquerAction(): Action
    {
        return Action::make('toutRevoquer')
            ->label('Tout révoquer')
            ->icon(Heroicon::OutlinedShieldExclamation)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Révoquer tous les jetons actifs ?')
            ->authorize(static fn (): bool => self::canAccess())
            ->action(function (): void {
                $revoques = resolve(RevoqueLesJetonsDeDiagnosticAction::class)->execute(RaisonDeRevocationDuJeton::Manuelle, self::administrateur());

                Notification::make()->success()->title($revoques === [] ? 'Aucun jeton actif' : count($revoques).' jeton(s) révoqué(s)')->send();
            });
    }

    /**
     * Le jeton ne survit pas à sa fenêtre : sans elle, l'état du composant
     * ne le garde plus, même chiffré.
     */
    public function dehydrate(): void
    {
        if ($this->jetonChiffre === null) {
            return;
        }

        $affiche = array_any((array) $this->mountedActions, static fn (array $monte): bool => ($monte['name'] ?? null) === 'afficherLeJeton');

        if (! $affiche) {
            $this->jetonChiffre = null;
        }
    }

    private function jetonEnClair(): ?string
    {
        if ($this->jetonChiffre === null) {
            return null;
        }

        try {
            $jeton = decrypt($this->jetonChiffre);
        } catch (DecryptException) {
            return null;
        }

        return is_string($jeton) ? $jeton : null;
    }

    private function refuser(string $phrase): void
    {
        Notification::make()->danger()->title('Jeton non créé')->body($phrase)->send();
    }

    private static function etatDe(JetonDeDiagnostic $jeton): string
    {
        return match (true) {
            $jeton->revoque_le !== null => 'révoqué',
            $jeton->expire_le->lte(now()) => 'expiré',
            default => 'actif',
        };
    }

    private static function avecTexteBrut(mixed $portees): bool
    {
        return is_array($portees) && in_array(PorteeDeDiagnostic::TexteBrut->value, $portees, true);
    }

    private static function administrateur(): Admin
    {
        $administrateur = auth('admin')->user();

        return $administrateur instanceof Admin ? $administrateur : abort(403);
    }

    private static function secretTotp(Admin $administrateur): string
    {
        if (! array_key_exists('app_authentication_secret', $administrateur->getAttributes())) {
            return '';
        }

        $secret = $administrateur->getAppAuthenticationSecret();

        return is_string($secret) ? $secret : '';
    }

    private static function texte(mixed $valeur): string
    {
        return is_string($valeur) ? $valeur : '';
    }

    private static function entier(mixed $valeur): int
    {
        return is_numeric($valeur) ? (int) $valeur : 0;
    }

    /**
     * @return list<PorteeDeDiagnostic>
     */
    private static function portees(mixed $valeurs): array
    {
        if (! is_array($valeurs)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $valeur): ?PorteeDeDiagnostic => is_string($valeur) ? PorteeDeDiagnostic::tryFrom($valeur) : null,
            $valeurs,
        )));
    }
}

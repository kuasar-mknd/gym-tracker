<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Admin;
use Closure;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Facades\Filament;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Forms\Components\TextInput;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Component as LivewireComponent;
use SensitiveParameter;

/**
 * L'authentification par application du panneau, avec ses codes de récupération
 * (#2353).
 *
 * Les administrateurs enrôlés avant leur activation ont un secret et aucun code :
 * Filament lève une `LogicException` (500) quand l'un d'eux, à la connexion,
 * choisit « utiliser un code de récupération ». Sans code rangé, aucun ne peut
 * être bon : le champ le dit comme pour un code faux, et l'administrateur
 * génère les siens depuis son profil.
 */
final class AuthentificationParApplication extends AppAuthentication
{
    /**
     * @return array<string>
     */
    #[\Override]
    public function getRecoveryCodes(HasAppAuthenticationRecovery $user): array
    {
        return $user->getAppAuthenticationRecoveryCodes() ?? [];
    }

    /**
     * Le marqueur anti-rejeu d'un code vit sur le magasin durable, comme les
     * limiteurs (#2151) : sur le cache par défaut, une éviction réautorisait un
     * code déjà employé.
     */
    #[\Override]
    protected function getCacheRepository(): Repository
    {
        $depot = MagasinDurable::depot();

        return $depot instanceof Repository ? $depot : parent::getCacheRepository();
    }

    /**
     * Le secret enrôlé par l'administrateur connecté est un second facteur qu'il
     * vient de prouver : sa session en porte la preuve (`AssuranceDuSecondFacteur`).
     */
    #[\Override]
    public function saveSecret(HasAppAuthentication $user, #[SensitiveParameter] ?string $secret): void
    {
        parent::saveSecret($user, $secret);

        if ($user instanceof Admin && Filament::auth()->user()?->is($user) === true) {
            AssuranceDuSecondFacteur::marquer($user);
        }
    }

    /**
     * Régénérer les codes exige le code de l'application ET le mot de passe,
     * comme la désactivation : Filament ne demandait que l'un des deux.
     *
     * @return array<Action>
     */
    #[\Override]
    public function getActions(): array
    {
        $motDePasse = TextInput::make('password')
            ->label(__('filament-panels::auth/multi-factor/app/actions/regenerate-recovery-codes.modal.form.password.label'))
            ->validationAttribute(__('filament-panels::auth/multi-factor/app/actions/regenerate-recovery-codes.modal.form.password.validation_attribute'))
            ->currentPassword(guard: Filament::getAuthGuard())
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->dehydrated(false);

        return array_map(function (Action $action) use ($motDePasse): Action {
            if ($action->getName() !== 'regenerateAppAuthenticationRecoveryCodes') {
                return $action;
            }

            return $action
                ->schema([
                    OneTimeCodeInput::make('code')
                        ->label(__('filament-panels::auth/multi-factor/app/actions/regenerate-recovery-codes.modal.form.code.label'))
                        ->validationAttribute(__('filament-panels::auth/multi-factor/app/actions/regenerate-recovery-codes.modal.form.code.validation_attribute'))
                        ->required()
                        ->rule(fn (): Closure => function (string $attribute, #[SensitiveParameter] mixed $value, Closure $fail): void {
                            if (! is_string($value) || ! $this->verifyCode($value, shouldPreventCodeReuse: true)) {
                                $fail(__('filament-panels::auth/multi-factor/app/actions/regenerate-recovery-codes.modal.form.code.messages.invalid'));
                            }
                        }),
                    $motDePasse,
                ])
                ->beforeFormValidated(function (LivewireComponent $livewire) use ($motDePasse): void {
                    $cheminDuMotDePasse = (string) $motDePasse->getStatePath();
                    $cleDuLimiteur = 'filament:auth:regenerate_recovery_codes:rate_limit:'.Filament::getUserScopedAuthIdentifier();

                    if (RateLimiter::tooManyAttempts($cleDuLimiteur, maxAttempts: 5)) {
                        throw ValidationException::withMessages([
                            $cheminDuMotDePasse => __('filament-panels::auth/multi-factor/app/actions/regenerate-recovery-codes.modal.form.code.messages.rate_limited'),
                        ]);
                    }

                    RateLimiter::hit($cleDuLimiteur);

                    $livewire->validateOnly(
                        $cheminDuMotDePasse,
                        [$cheminDuMotDePasse => $motDePasse->getValidationRules()],
                        attributes: [$cheminDuMotDePasse => $motDePasse->getValidationAttribute()],
                    );
                });
        }, parent::getActions());
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Connexion;

use App\Models\Admin;
use App\Support\AssuranceDuSecondFacteur;
use App\Support\DefiDeDoubleAuthentification;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;

/**
 * La connexion du panneau : le défi a son plafond journalier (5.4), et la
 * session ouverte porte la preuve du second facteur (5.2).
 */
final class ConnexionDuPanneau extends Login
{
    #[\Override]
    public function authenticate(): ?LoginResponse
    {
        $reponse = parent::authenticate();
        $administrateur = Filament::auth()->user();

        if ($reponse !== null && $administrateur instanceof Admin) {
            AssuranceDuSecondFacteur::marquer($administrateur);
        }

        return $reponse;
    }

    #[\Override]
    protected function getMultiFactorChallenge(): \App\Support\DefiDeDoubleAuthentification
    {
        return DefiDeDoubleAuthentification::make();
    }
}

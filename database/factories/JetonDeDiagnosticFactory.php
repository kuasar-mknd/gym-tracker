<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PorteeDeDiagnostic;
use App\Enums\RaisonDeRevocationDuJeton;
use App\Models\Admin;
use App\Models\JetonDeDiagnostic;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<JetonDeDiagnostic>
 */
class JetonDeDiagnosticFactory extends Factory
{
    /**
     * Un jeton actif de sept jours, à la portée `Sante`. Le secret en clair
     * ne se garde pas : une fabrique qui en a besoin le tire elle-même.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'identifiant' => Str::lower(Str::random(JetonDeDiagnostic::LONGUEUR_DE_L_IDENTIFIANT)),
            'empreinte' => JetonDeDiagnostic::empreinteDe(Str::random(JetonDeDiagnostic::LONGUEUR_DU_SECRET)),
            'nom' => 'Agent de maintenance',
            'portees' => [PorteeDeDiagnostic::Sante->value],
            'cree_par_admin_id' => Admin::factory(),
            'expire_le' => now()->addDays(JetonDeDiagnostic::DUREE_PAR_DEFAUT),
        ];
    }

    public function expire(): self
    {
        return $this->state(fn (): array => ['expire_le' => now()->subMinute()]);
    }

    public function revoque(RaisonDeRevocationDuJeton $raison = RaisonDeRevocationDuJeton::Manuelle): self
    {
        return $this->state(fn (): array => [
            'revoque_le' => now(),
            'raison_de_revocation' => $raison->value,
        ]);
    }
}

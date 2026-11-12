<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RaisonDeRevocationDuJeton;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SensitiveParameter;

/**
 * Un jeton de l'accès de diagnostic (#2032).
 *
 * `gtdiag_<identifiant>_<secret>` : l'identifiant (12 caractères
 * `[a-z0-9]`) le désigne, le secret (48 caractères `[A-Za-z0-9]`) ne se garde
 * pas : seule son empreinte sha256 est en base. Le motif est celui de
 * l'expurgation (`MasqueLesSecrets::MOTIF_DU_JETON`), faute de quoi le
 * masque ne reconnaîtrait pas ce que le générateur écrit.
 *
 * @property int $id
 * @property string $identifiant
 * @property string $empreinte
 * @property string $nom
 * @property list<string> $portees
 * @property int|null $cree_par_admin_id
 * @property CarbonInterface $expire_le
 * @property CarbonInterface|null $revoque_le
 * @property int|null $revoque_par_admin_id
 * @property string|null $raison_de_revocation
 * @property CarbonInterface|null $derniere_utilisation_le
 * @property string|null $derniere_adresse
 */
class JetonDeDiagnostic extends Model
{
    /** @use HasFactory<\Database\Factories\JetonDeDiagnosticFactory> */
    use HasFactory, Prunable;

    public const string PREFIXE = 'gtdiag_';

    public const int LONGUEUR_DE_L_IDENTIFIANT = 12;

    public const int LONGUEUR_DU_SECRET = 48;

    /** Le plus de jetons actifs à la fois. */
    public const int ACTIFS_AU_PLUS = 2;

    /** Les durées permises, en jours. */
    public const array DUREES = [1, 7, 30];

    public const int DUREE_PAR_DEFAUT = 7;

    /** Un jeton qui porte `TexteBrut` vit un jour au plus. */
    public const int DUREE_AVEC_TEXTE_BRUT = 1;

    /** Combien de jours un jeton terminé reste en base. */
    public const int JOURS_DE_CONSERVATION = 90;

    #[\Override]
    protected $table = 'jetons_de_diagnostic';

    /**
     * @var list<string>
     */
    #[\Override]
    protected $fillable = [
        'identifiant',
        'empreinte',
        'nom',
        'portees',
        'cree_par_admin_id',
        'expire_le',
    ];

    /**
     * @var list<string>
     */
    #[\Override]
    protected $hidden = ['empreinte'];

    /**
     * Le jeton en clair, de son identifiant et de son secret.
     */
    public static function composer(string $identifiant, #[SensitiveParameter] string $secret): string
    {
        return self::PREFIXE.$identifiant.'_'.$secret;
    }

    public static function empreinteDe(#[SensitiveParameter] string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function createur(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'cree_par_admin_id');
    }

    /**
     * Ni révoqués ni expirés, à l'instant donné.
     *
     * @param  Builder<self>  $requete
     * @return Builder<self>
     */
    public function scopeActifs(Builder $requete, CarbonInterface $instant): Builder
    {
        return $requete->whereNull('revoque_le')->where('expire_le', '>', $instant);
    }

    /**
     * Révoque le jeton, une fois : un jeton déjà révoqué garde sa première
     * raison. Rend vrai si cet appel l'a révoqué.
     */
    public function revoquer(RaisonDeRevocationDuJeton $raison, ?Admin $par, CarbonInterface $instant): bool
    {
        return self::query()
            ->whereKey($this->getKey())
            ->whereNull('revoque_le')
            ->update([
                'revoque_le' => $instant,
                'revoque_par_admin_id' => $par?->getKey(),
                'raison_de_revocation' => $raison->value,
            ]) === 1;
    }

    /**
     * Les jetons terminés, par révocation ou par expiration, depuis plus de
     * `JOURS_DE_CONSERVATION` jours.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $limite = now()->subDays(self::JOURS_DE_CONSERVATION);

        return self::query()->where(function (Builder $requete) use ($limite): void {
            $requete->where('expire_le', '<=', $limite)->orWhere('revoque_le', '<=', $limite);
        });
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'portees' => 'array',
            'expire_le' => 'datetime',
            'revoque_le' => 'datetime',
            'derniere_utilisation_le' => 'datetime',
        ];
    }
}

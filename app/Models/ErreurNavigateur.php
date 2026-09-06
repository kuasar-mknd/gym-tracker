<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une erreur survenue dans le navigateur d'un utilisateur, rapportée par la
 * page elle-même : ce que Sentry recevait, gardé ici.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $type
 * @property string $message
 * @property string|null $source
 * @property int|null $ligne
 * @property int|null $colonne
 * @property string|null $pile
 * @property string $url
 * @property string|null $agent
 * @property string $empreinte
 * @property \Illuminate\Support\Carbon $created_at
 */
final class ErreurNavigateur extends Model
{
    use MassPrunable;

    public const array TYPES = ['error', 'unhandledrejection', 'vue'];

    #[\Override]
    protected $table = 'erreurs_navigateur';

    #[\Override]
    protected $fillable = ['user_id', 'type', 'message', 'source', 'ligne', 'colonne', 'pile', 'url', 'agent', 'empreinte'];

    /** L'empreinte regroupe les occurrences d'une même erreur. */
    public static function empreinteDe(string $type, string $message, ?string $source, ?int $ligne): string
    {
        return md5($type.'|'.$message.'|'.($source ?? '').'|'.($ligne ?? ''));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Trente jours suffisent à comprendre une panne.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return self::query()->where('created_at', '<=', now()->subDays(30));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ligne' => 'integer',
            'colonne' => 'integer',
        ];
    }
}

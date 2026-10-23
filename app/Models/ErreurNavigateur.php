<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * Une erreur survenue dans le navigateur d'un utilisateur, rapportée par la
 * page elle-même : ce que Sentry recevait, gardé ici.
 *
 * Elle part à trente jours (`prunable()`), ou avec le compte qui l'a
 * rapportée : la clé de `user_id` est en cascade (#1938), parce que le
 * message et l'URL peuvent porter ce que le compte a saisi.
 *
 * Une ligne est la première occurrence d'une erreur pour un compte sur une
 * heure (`SECONDES_DU_REGROUPEMENT`) : la route de collecte ne réécrit pas
 * une empreinte déjà gardée pour ce compte dans la fenêtre, et se plafonne
 * par compte (`RAPPORTS_PAR_MINUTE`, `RAPPORTS_PAR_JOUR`).
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

    /**
     * La ligne ou la colonne la plus grande d'une erreur rapportée : dix
     * millions. Un morceau compilé tient sur une ligne de quelques centaines
     * de kilo-octets ; la colonne (unsigned int) va bien au-delà.
     */
    public const int POSITION_MAX = 10_000_000;

    /**
     * Les caractères d'une pile que garde la colonne `pile` : un `TEXT` de
     * 65 535 octets, à quatre octets au plus par caractère en utf8mb4. La
     * page en envoie jusqu'à 20 000 unités UTF-16 (`rapporteurDErreurs.js`) ;
     * au-delà de ce que la colonne tient, la base refusait l'écriture, et le
     * rapport finissait en 500.
     */
    public const int PILE_MAX_CARACTERES = 16_383;

    /**
     * Le limiteur propre à la route de collecte (`erreurs-navigateur.store`),
     * défini par `AppServiceProvider::plafonnerLaCollecteDesErreursDuNavigateur()`.
     */
    public const string LIMITEUR = 'erreurs-navigateur';

    /**
     * Les rapports qu'un compte peut envoyer en une minute : dix, autant que
     * le rapporteur de la page en envoie au plus (`rapporteurDErreurs.js`).
     * Au-delà, la route répond 204 sans rien écrire.
     */
    public const int RAPPORTS_PAR_MINUTE = 10;

    /**
     * Les rapports qu'un compte peut envoyer en une journée, au-delà desquels
     * la route répond 204 sans rien écrire.
     */
    public const int RAPPORTS_PAR_JOUR = 200;

    /**
     * La fenêtre, une heure, sur laquelle une même erreur (même empreinte) du
     * même compte ne s'écrit qu'une fois : la première occurrence est gardée,
     * les suivantes ne réécrivent rien.
     */
    public const int SECONDES_DU_REGROUPEMENT = 3_600;

    #[\Override]
    protected $table = 'erreurs_navigateur';

    #[\Override]
    protected $fillable = ['user_id', 'type', 'message', 'source', 'ligne', 'colonne', 'pile', 'url', 'agent', 'empreinte'];

    /** L'empreinte regroupe les occurrences d'une même erreur. */
    public static function empreinteDe(string $type, string $message, ?string $source, ?int $ligne): string
    {
        return md5($type.'|'.$message.'|'.($source ?? '').'|'.($ligne ?? ''));
    }

    /**
     * La clé de cache qui retient, le temps de `SECONDES_DU_REGROUPEMENT`,
     * qu'une erreur de cette empreinte a déjà été gardée pour ce compte.
     */
    public static function cleDuRegroupement(int $compte, string $empreinte): string
    {
        return "erreurs-navigateur:{$compte}:{$empreinte}";
    }

    /**
     * Une ligne effacée du panneau rend sa clé de regroupement : l'erreur qui
     * se reproduit dans l'heure s'écrit de nouveau, et la liste ne la montre
     * pas comme disparue. La purge (`MassPrunable`) et l'effacement du compte
     * (clé étrangère en cascade) ne passent pas par ici, mais ne touchent que des lignes
     * de plus de trente jours, ou un compte qui n'enverra plus rien.
     */
    #[\Override]
    protected static function booted(): void
    {
        self::deleted(static function (self $erreur): void {
            if ($erreur->user_id !== null) {
                Cache::forget(self::cleDuRegroupement($erreur->user_id, $erreur->empreinte));
            }
        });
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

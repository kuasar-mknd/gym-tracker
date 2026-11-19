<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ExceptionEnregistree;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * Décide, avant tout rendu, si une exception rapportée s'écrit en base : une
 * ligne complète par empreinte (classe, fichier, ligne, message normalisé)
 * et par fenêtre, au plus `PLAFOND_PAR_MINUTE` lignes par minute. Les autres
 * occurrences se comptent dans le cache, sans SQL, et le compte rejoint la
 * ligne de l'empreinte quand la fenêtre suivante s'ouvre.
 *
 * Si le cache refuse (Redis saturé), la décision se lit en base : une
 * lecture, jamais une écriture de plus.
 */
final class AdmissionDesExceptions
{
    public const int FENETRE_EN_SECONDES = 600;

    public const int PLAFOND_PAR_MINUTE = 30;

    private const int VIE_DES_COMPTEURS = 86_400;

    private const string CONTEXTE = 'empreinte_de_l_exception';

    public function admet(Throwable $exception): bool
    {
        $empreinte = self::empreinte($exception);

        try {
            $admise = $this->admetSelonLeCache($empreinte);
        } catch (Throwable) {
            $admise = rescue(fn (): bool => $this->admetSelonLaBase($empreinte), false, false);
        }

        if ($admise) {
            Context::add(self::CONTEXTE, $empreinte);
        }

        return $admise;
    }

    /**
     * L'empreinte que la ligne en cours d'écriture doit porter, une seule fois.
     */
    public function empreinteEnCours(): ?string
    {
        $empreinte = Context::pull(self::CONTEXTE);

        return is_string($empreinte) ? $empreinte : null;
    }

    public function retenir(string $empreinte, int $identifiant): void
    {
        rescue(fn () => Cache::put($this->clef('ligne', $empreinte), $identifiant, self::VIE_DES_COMPTEURS), null, false);
    }

    public static function empreinte(Throwable $exception): string
    {
        return hash('xxh128', implode('|', [
            $exception::class,
            $exception->getFile(),
            $exception->getLine(),
            self::messageNormalise($exception->getMessage()),
        ]));
    }

    /**
     * Le message sans ce qui varie d'une occurrence à l'autre : valeurs entre
     * guillemets, adresses, suites hexadécimales (préfixe d'un hachage, jeton,
     * identifiant) et nombres.
     */
    private static function messageNormalise(string $message): string
    {
        $sansValeurs = preg_replace(
            ['/\'[^\']*\'|"[^"]*"/', '/[^\s@]+@[^\s@]+/', '/[0-9a-f]{5,}/i', '/\d+/', '/\s+/'],
            ['?', '@', '#', '#', ' '],
            $message,
        ) ?? $message;

        return mb_substr(trim($sansValeurs), 0, 200);
    }

    private function admetSelonLeCache(string $empreinte): bool
    {
        if (! Cache::add($this->clef('fenetre', $empreinte), 1, self::FENETRE_EN_SECONDES)) {
            $this->compterLaRepetition($empreinte);

            return false;
        }

        if (! $this->sousLePlafondDeLaMinute()) {
            Cache::forget($this->clef('fenetre', $empreinte));

            return false;
        }

        $this->verserLeCompte($empreinte);

        return true;
    }

    private function sousLePlafondDeLaMinute(): bool
    {
        $clef = 'exceptions:minute:'.now()->format('YmdHi');

        Cache::add($clef, 0, 120);

        return Cache::increment($clef) <= self::PLAFOND_PAR_MINUTE;
    }

    /**
     * Une répétition de plus, dans le cache. Aux rangs 10, 100, 1 000… le
     * total est écrit sur la ligne : une rafale qui s'arrête garde son
     * compte, pour quelques écritures seulement.
     */
    private function compterLaRepetition(string $empreinte): void
    {
        $clef = $this->clef('compte', $empreinte);

        Cache::add($clef, 0, self::VIE_DES_COMPTEURS);
        $nombre = Cache::increment($clef);

        if (is_int($nombre) && preg_match('/^10+$/', (string) $nombre) === 1) {
            $this->ecrireLeTotal($empreinte, $nombre);
        }
    }

    /**
     * Le compte des occurrences passées sous silence va à la ligne de la
     * fenêtre qui se ferme : une écriture, comptée dans l'admission.
     */
    private function verserLeCompte(string $empreinte): void
    {
        $nombre = Cache::pull($this->clef('compte', $empreinte));

        if (is_numeric($nombre) && (int) $nombre > 0) {
            $this->ecrireLeTotal($empreinte, (int) $nombre);
        }
    }

    /**
     * Le total est absolu (la ligne plus les répétitions) : le rejouer ne
     * compte rien deux fois.
     */
    private function ecrireLeTotal(string $empreinte, int $repetitions): void
    {
        $ligne = Cache::get($this->clef('ligne', $empreinte));

        if (is_numeric($ligne)) {
            rescue(fn () => ExceptionEnregistree::query()->whereKey((int) $ligne)->update(['occurrences' => 1 + $repetitions]), null, false);
        }
    }

    private function admetSelonLaBase(string $empreinte): bool
    {
        $maintenant = now();

        $dejaEcrite = ExceptionEnregistree::query()
            ->where('empreinte', $empreinte)
            ->where('created_at', '>=', $maintenant->copy()->subSeconds(self::FENETRE_EN_SECONDES))
            ->exists();

        return ! $dejaEcrite
            && ExceptionEnregistree::query()->where('created_at', '>=', $maintenant->copy()->subMinute())->count() < self::PLAFOND_PAR_MINUTE;
    }

    private function clef(string $nature, string $empreinte): string
    {
        return sprintf('exceptions:%s:%s', $nature, $empreinte);
    }
}

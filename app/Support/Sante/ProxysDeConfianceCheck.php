<?php

declare(strict_types=1);

namespace App\Support\Sante;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * La liste des pairs dont l'application croit `X-Forwarded-*` : la liste
 * résolue de `config('trustedproxy.proxies')`, défaut compris, et non la
 * variable `TRUSTED_PROXIES`. Orange en production dès qu'une entrée couvre
 * plus qu'un /24 (IPv4) ou un /64 (IPv6) : un pair de ces plages
 * peut alors annoncer n'importe quelle adresse cliente ; une entrée qui n'est ni
 * une adresse ni un sous-réseau (`PRIVATE_SUBNETS`, `REMOTE_ADDR`, une faute
 * de frappe) est tenue pour large, et `*` ne fait confiance à aucun pair dans
 * cette liste. Le message ne dit que des nombres. Vert sinon. Hors
 * production, le contrôle ne juge rien.
 */
final class ProxysDeConfianceCheck extends Check
{
    private const int PLUS_LARGE_IPV4 = 24;

    private const int PLUS_LARGE_IPV6 = 64;

    /**
     * Le libellé de la page « Santé » et du courriel d'alerte, qui
     * découperaient sinon le nom de classe.
     */
    #[\Override]
    protected ?string $label = 'Proxys de confiance';

    #[\Override]
    public function getName(): string
    {
        return 'ProxysDeConfiance';
    }

    public function run(): Result
    {
        if (! app()->isProduction()) {
            return Result::make()
                ->shortSummary('Non jugé hors production')
                ->ok('Ce contrôle ne juge que la production.');
        }

        $proxys = $this->proxys();
        $inertes = array_values(array_filter($proxys, $this->nExempteAucunPair(...)));
        $larges = array_values(array_filter($proxys, fn (string $proxy): bool => ! $this->nExempteAucunPair($proxy) && $this->estTropLarge($proxy)));
        $resume = count($proxys).' entrée(s)';
        $result = Result::make()
            ->meta(['entrees' => count($proxys), 'larges' => count($larges), 'inertes' => count($inertes)])
            ->shortSummary($resume);

        $constats = [];

        if ($larges !== []) {
            $constats[] = count($larges).' trop large(s) ou invalide(s)';
        }

        if ($inertes !== []) {
            $constats[] = count($inertes).' qui ne fait confiance à aucun pair (le schéma HTTPS ne serait plus détecté)';
        }

        if ($constats === []) {
            return $result->ok($resume);
        }

        return $result->warning(
            $resume.' dont '.implode(' et ', $constats).'. '
            .'Régler TRUSTED_PROXIES sur l\'adresse précise sous laquelle le proxy inverse joint l\'application.',
        );
    }

    /**
     * @return list<string>
     */
    private function proxys(): array
    {
        $liste = config('trustedproxy.proxies');

        if (! is_array($liste)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $proxy): string => is_string($proxy) ? trim($proxy) : '', $liste),
            static fn (string $proxy): bool => $proxy !== '',
        ));
    }

    /**
     * `*` et `**` n'ont de sens que comme valeur seule : dans la liste que
     * `config/trustedproxy.php` rend toujours, ils ne correspondent à aucune
     * adresse et la confiance tombe à zéro.
     */
    private function nExempteAucunPair(string $proxy): bool
    {
        return str_starts_with($proxy, '*');
    }

    /**
     * Ce qui n'est pas une adresse, ou un sous-réseau plus large que la
     * limite, est tenu pour large : `PRIVATE_SUBNETS`, une faute de frappe,
     * `REMOTE_ADDR` (remplacé par l'adresse du pair qui se connecte, donc
     * tout client). Une plage IPv4 mappée en IPv6 se juge sur son IPv4.
     */
    private function estTropLarge(string $proxy): bool
    {
        $morceaux = explode('/', $proxy, 2);
        $octets = filter_var($morceaux[0], FILTER_VALIDATE_IP) === false ? false : inet_pton($morceaux[0]);

        if ($octets === false) {
            return true;
        }

        $prefixe = $morceaux[1] ?? null;

        if ($prefixe === null) {
            return false;
        }

        if (! ctype_digit($prefixe)) {
            return true;
        }

        return (int) $prefixe < $this->limiteDeLAdresse($octets);
    }

    /**
     * Le préfixe minimal toléré, exprimé dans la famille de l'adresse : un
     * /24 d'IPv4, un /64 d'IPv6, et 96 de plus pour une IPv4 mappée.
     */
    private function limiteDeLAdresse(string $octets): int
    {
        return match (true) {
            strlen($octets) === 4 => self::PLUS_LARGE_IPV4,
            str_starts_with($octets, str_repeat("\0", 10)."\xff\xff") => 96 + self::PLUS_LARGE_IPV4,
            default => self::PLUS_LARGE_IPV6,
        };
    }
}

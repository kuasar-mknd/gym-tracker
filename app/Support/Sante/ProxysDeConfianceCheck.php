<?php

declare(strict_types=1);

namespace App\Support\Sante;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * La liste des pairs dont l'application croit `X-Forwarded-*` : la liste
 * résolue de `config('trustedproxy.proxies')`, défaut compris, et non la
 * variable `TRUSTED_PROXIES`. Orange en production dès qu'une entrée couvre
 * plus qu'un /24 (IPv4) ou un /64 (IPv6), `*` compris : un pair de ces plages
 * peut alors annoncer n'importe quelle adresse cliente ; une entrée qui n'est ni
 * une adresse ni un sous-réseau (`PRIVATE_SUBNETS`, une faute de frappe) est
 * tenue pour large. Le message ne dit que des nombres. Vert sinon. Hors
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
        $larges = array_values(array_filter($proxys, $this->estTropLarge(...)));
        $resume = count($proxys).' entrée(s)';
        $result = Result::make()->meta(['entrees' => count($proxys), 'larges' => count($larges)])->shortSummary($resume);

        if ($larges === []) {
            return $result->ok($resume);
        }

        return $result->warning(
            $resume.' dont '.count($larges).' trop large(s). '
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
     * Ce qui n'est pas une adresse, ou un sous-réseau au plus aussi large
     * que la limite, est tenu pour large : `*`, `PRIVATE_SUBNETS`, une
     * faute de frappe.
     */
    private function estTropLarge(string $proxy): bool
    {
        if ($proxy === 'REMOTE_ADDR') {
            return false;
        }

        $morceaux = explode('/', $proxy, 2);
        $ipv6 = filter_var($morceaux[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        if (! $ipv6 && filter_var($morceaux[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return true;
        }

        $prefixe = $morceaux[1] ?? null;

        if ($prefixe === null) {
            return false;
        }

        return ! ctype_digit($prefixe) || (int) $prefixe < ($ipv6 ? self::PLUS_LARGE_IPV6 : self::PLUS_LARGE_IPV4);
    }
}

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

    private const string CONSEIL = 'Régler TRUSTED_PROXIES sur l\'adresse précise sous laquelle le proxy inverse joint l\'application.';

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
        $constats = $this->constats($proxys, count($larges), count($inertes));

        return $constats === []
            ? $result->ok($resume)
            : $result->warning($resume.' dont '.implode(' et ', $constats).'. '.self::CONSEIL);
    }

    /**
     * @param  list<string>  $proxys
     * @return list<string>
     */
    private function constats(array $proxys, int $larges, int $inertes): array
    {
        $constats = [];

        if ($larges > 0) {
            $constats[] = $larges.' trop large(s) ou invalide(s)';
        }

        if ($inertes > 0) {
            $constats[] = $inertes.' qui ne fait confiance à aucun pair'.$this->suiteDesInertes($proxys);
        }

        return $constats;
    }

    /**
     * @param  list<string>  $proxys
     */
    private function suiteDesInertes(array $proxys): string
    {
        return $this->resteUneEntreeUtilisable($proxys) ? '' : ' (le schéma HTTPS ne serait plus détecté)';
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
     * @param  list<string>  $proxys
     */
    private function resteUneEntreeUtilisable(array $proxys): bool
    {
        return array_any($proxys, fn (string $proxy): bool => ! $this->nExempteAucunPair($proxy) && $this->lire($proxy) !== null);
    }

    /**
     * Les octets de l'adresse et son préfixe (sa longueur entière si elle
     * n'en a pas), ou `null` quand l'entrée n'est pas une adresse ni un
     * sous-réseau que Symfony saurait comparer : un préfixe hors de la
     * famille (`/33`, `/129`) ne correspond à rien.
     *
     * @return array{0: string, 1: int}|null
     */
    private function lire(string $proxy): ?array
    {
        $morceaux = explode('/', $proxy, 2);
        $octets = $this->octetsDeLAdresse($morceaux[0]);

        if ($octets === null) {
            return null;
        }

        $longueur = strlen($octets) * 8;
        $prefixe = $morceaux[1] ?? (string) $longueur;

        return $this->prefixeValide($prefixe, $longueur) ? [$octets, (int) $prefixe] : null;
    }

    private function octetsDeLAdresse(string $adresse): ?string
    {
        $octets = filter_var($adresse, FILTER_VALIDATE_IP) === false ? false : inet_pton($adresse);

        return $octets === false ? null : $octets;
    }

    private function prefixeValide(string $prefixe, int $longueur): bool
    {
        return ctype_digit($prefixe) && (int) $prefixe <= $longueur;
    }

    /**
     * Ce qui n'est pas une adresse, ou un sous-réseau plus large que la
     * limite, est tenu pour large : `PRIVATE_SUBNETS`, une faute de frappe,
     * `REMOTE_ADDR` (remplacé par l'adresse du pair qui se connecte, donc
     * tout client). Une plage IPv4 mappée en IPv6 se juge sur son IPv4, et
     * une plage IPv6 qui contient le bloc mappé (`::/80`) couvre des clients
     * IPv4 de toute adresse.
     */
    private function estTropLarge(string $proxy): bool
    {
        $lu = $this->lire($proxy);

        if ($lu === null) {
            return true;
        }

        [$octets, $prefixe] = $lu;

        return $prefixe < $this->limiteDeLAdresse($octets, $prefixe);
    }

    /**
     * Le préfixe minimal toléré, exprimé dans la famille de l'adresse : un
     * /24 d'IPv4, un /64 d'IPv6, et 96 de plus pour une IPv4 mappée.
     */
    private function limiteDeLAdresse(string $octets, int $prefixe): int
    {
        return match (true) {
            strlen($octets) === 4 => self::PLUS_LARGE_IPV4,
            $this->contientLeBlocMappe($octets, $prefixe) => 96 + self::PLUS_LARGE_IPV4,
            default => self::PLUS_LARGE_IPV6,
        };
    }

    /**
     * La plage `octets/prefixe` contient-elle, ou est-elle contenue dans,
     * `::ffff:0:0/96` ? Ses `min(prefixe, 96)` premiers bits sont ceux du bloc.
     */
    private function contientLeBlocMappe(string $octets, int $prefixe): bool
    {
        $bloc = str_repeat("\0", 10)."\xff\xff".str_repeat("\0", 4);
        $bits = min($prefixe, 96);
        $entiers = intdiv($bits, 8);

        if (substr($octets, 0, $entiers) !== substr($bloc, 0, $entiers)) {
            return false;
        }

        $reste = $bits % 8;
        $decalage = 8 - $reste;

        return $reste === 0 || ord($octets[$entiers]) >> $decalage === ord($bloc[$entiers]) >> $decalage;
    }
}

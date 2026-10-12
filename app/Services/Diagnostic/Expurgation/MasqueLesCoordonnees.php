<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Expurgation;

/**
 * Sixième étape de l'expurgation : les courriels, les adresses IP, les noms
 * d'hôte, les chaînes de requête et les fragments remplacés par une marque.
 *
 * Un nom d'hôte se reconnaît à son dernier label : une extension connue
 * (`org`, `com`, `example`, `test`…) ou un code de pays de deux lettres, hors
 * extensions de fichier (`app.js`, `Connection.php` restent). Une adresse
 * IPv6 doit porter `::` ou huit groupes, pour qu'une heure (`12:30:45`) ne
 * soit pas prise pour elle. Une chaîne de requête ou un fragment ne se
 * reconnaissent qu'accrochés à un mot ou à un chemin (`/seances?note=…`) :
 * le `?` d'une requête SQL (`values (?, ?)`) et le `#0` d'une pile restent.
 * Chaque répétition est bornée.
 */
final class MasqueLesCoordonnees
{
    private const string EXTENSIONS_DE_FICHIER = '(?:js|ts|sh|md|py|rb|rs|pl|cs|db|gz|so|vue|php|css|json|html|log|sql|txt|zip|xml|yml|yaml|ini|lock|map|mjs|cjs|csv|svg|png|jpg|gif|ico|woff2?)';

    private const string DERNIER_LABEL = '(?:[a-z]{2}|com|org|net|edu|gov|mil|int|info|biz|app|dev|cloud|online|site|tech|xyz|example|test|invalid|localhost|local|internal|lan|home|corp|arpa|onion)';

    /**
     * Chaque motif et sa marque, dans l'ordre où ils s'appliquent.
     *
     * @var array<string, string>
     */
    private const array MOTIFS = [
        '/[\w.+-]{1,64}@(?:\[[^\]\n]{1,64}\]|(?:[\w-]{1,63}\.){1,8}[\w-]{2,24})/u' => '[courriel]',
        '/(?<![\w.:])(?:\d{1,3}\.){3}\d{1,3}(?![\w.])/' => '[adresse IP]',
        '/(?<![\w:.])(?:[0-9a-f]{1,4}:){1,7}:(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6})?(?![\w:])|(?<![\w:.])::[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6}(?![\w:])|(?<![\w:.])(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}(?![\w:])/i' => '[adresse IP]',
        '/(?<![\w.@-])(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.){1,8}(?!'.self::EXTENSIONS_DE_FICHIER.'(?![\w-]))'.self::DERNIER_LABEL.'(?![\w-])(?::\d{1,5}(?!\d))?/i' => '[hôte]',
        '/(?<=[\w\/\]])\?[^\s#\'"<>]{1,2048}/' => '?[requête]',
        '/(?<=[\w\/\]])#[^\s\'"<>]{1,2048}/' => '#[fragment]',
    ];

    public function __invoke(string $texte): string
    {
        return array_reduce(
            array_keys(self::MOTIFS),
            static fn (string $partiel, string $motif): string => Motifs::remplacer($motif, self::MOTIFS[$motif], $partiel),
            $texte,
        );
    }
}

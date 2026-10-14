<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Expurgation;

/**
 * Sixième étape de l'expurgation : les courriels, les adresses IP, les noms
 * d'hôte, les chaînes de requête et les fragments remplacés par une marque.
 *
 * Une adresse IPv4 se reconnaît à ses quatre nombres, quel que soit son
 * voisin (un point qui finit la phrase, un deux-points), sauf un nombre
 * collé ou un cinquième nombre pointé : `1.2.3.4.5` est une version. Une
 * adresse IPv6 doit porter `::` ou huit groupes, pour qu'une heure
 * (`12:30:45`) ne soit pas prise pour elle ; écrite avec une adresse IPv4
 * (`::ffff:203.0.113.7`), elle est marquée en entier, avant les adresses
 * IPv4.
 *
 * Un nom d'hôte de deux labels se reconnaît à son dernier : une extension
 * connue (`org`, `com`, `example`, `test`…) ou un code de pays de deux
 * lettres ; ou à ce qui le précède : `//`, `@`, « host », « hôte ». À partir
 * de trois labels, tout dernier label de deux à vingt-quatre lettres suffit,
 * pour les extensions que la liste ne nomme pas (`smtp.salle.systems`) : un
 * nom de route ou une clé à trois labels (`admin.users.index`) est marqué
 * avec eux, ce qui ne fait rien perdre que la structure ne dise déjà. Un
 * hôte ne finit jamais devant un point suivi d'un label : un label du milieu
 * qui est une extension connue ou un code de pays (`relais.us.salle.systems`)
 * ne coupe pas l'hôte, que le motif des trois labels prend en entier. Une
 * extension de fichier n'est jamais un dernier label (`app.js`,
 * `jquery.min.js` et `Connection.php` restent), et un label peut porter des
 * lettres accentuées.
 *
 * Une chaîne de requête ou un fragment ne se reconnaissent qu'accrochés à un
 * mot ou à un chemin (`/seances?note=…`) : le `?` d'une requête SQL
 * (`values (?, ?)`) et le `#0` d'une pile restent. Chaque répétition est
 * bornée.
 */
final class MasqueLesCoordonnees
{
    private const string EXTENSIONS_DE_FICHIER = '(?:js|ts|sh|md|py|rb|rs|pl|cs|db|gz|so|vue|php|css|json|html|log|sql|txt|zip|xml|yml|yaml|ini|lock|map|mjs|cjs|csv|svg|png|jpg|gif|ico|woff2?)';

    private const string DERNIER_LABEL_CONNU = '(?:[a-z]{2}|com|org|net|edu|gov|mil|int|info|biz|app|dev|cloud|online|site|tech|xyz|example|test|invalid|localhost|local|internal|lan|home|corp|arpa|onion)';

    private const string LABEL = '[\p{L}\p{N}](?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?';

    private const string FIN_DE_L_HOTE = '(?![\w-]|\.[\p{L}\p{N}])(?::\d{1,5}(?!\d))?';

    private const string ADRESSE_IPV4 = '(?:\d{1,3}\.){3}\d{1,3}';

    private const string APRES_UNE_ADRESSE = '(?![\w:]|\.\d)';

    /**
     * Chaque motif et sa marque, dans l'ordre où ils s'appliquent.
     *
     * @var array<string, string>
     */
    private const array MOTIFS = [
        '/[\w.+-]{1,64}@(?:\[[^\]\n]{1,64}\]|(?:[\w-]{1,63}\.){1,8}[\w-]{2,24})/u' => '[courriel]',
        '/(?<![\w:])(?:[0-9a-f]{1,4}:){1,7}:(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6})?(?::'.self::ADRESSE_IPV4.')?'.self::APRES_UNE_ADRESSE
            .'|(?<![\w:])::[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,6}(?::'.self::ADRESSE_IPV4.')?'.self::APRES_UNE_ADRESSE
            .'|(?<![\w:])(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}'.self::APRES_UNE_ADRESSE.'/i' => '[adresse IP]',
        '/(?<![0-9A-Za-z]|\d\.)'.self::ADRESSE_IPV4.'(?![0-9A-Za-z]|\.\d)/' => '[adresse IP]',
        '/(?<![\w.@-])(?:'.self::LABEL.'\.){1,8}(?!'.self::EXTENSIONS_DE_FICHIER.'(?![\w-]))'.self::DERNIER_LABEL_CONNU.self::FIN_DE_L_HOTE.'/iu' => '[hôte]',
        '/(?<![\w.@-])(?:'.self::LABEL.'\.){2,8}(?!'.self::EXTENSIONS_DE_FICHIER.'(?![\w-]))\p{L}{2,24}'.self::FIN_DE_L_HOTE.'/iu' => '[hôte]',
        '/(?<=\/\/|@|host |host=|host:|host: |hôte |hôte : )'.self::LABEL.'\.(?!'.self::EXTENSIONS_DE_FICHIER.'(?![\w-]))\p{L}{2,24}'.self::FIN_DE_L_HOTE.'/iu' => '[hôte]',
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

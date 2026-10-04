<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\SocialiteManager;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256 as Rs256;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * Apple, joué de bout en bout sans quitter la machine (#1911).
 *
 * Deux clés tirées à chaque instance, jamais une vraie : la clé « .p8 » de
 * l'application (EC P-256), qui signe le secret client, et la clé d'Apple
 * (RSA 2048), qui signe le jeton d'identité. Le point d'échange d'Apple répond
 * par une pile Guzzle simulée, que le paquet reçoit par `services.apple.guzzle`
 * et qui garde chaque requête partie ; ses clés publiques sont déjà dans le
 * cache que le paquet consulte avant d'aller les chercher.
 */
final class AppleSimule
{
    public const string SERVICES_ID = 'org.example.gym.web';

    public const string EQUIPE = 'EQUIPE0001';

    public const string IDENTIFIANT_DE_CLE = 'CLEAPP0001';

    public const string IDENTIFIANT_DE_CLE_D_APPLE = 'cle-d-apple-de-test';

    /**
     * Le PEM de la clé .p8 de l'application, telle qu'APPLE_PRIVATE_KEY la porte.
     *
     * @var non-empty-string
     */
    public readonly string $clePriveeDeLApplication;

    /**
     * La moitié publique, qui vérifie le secret client signé.
     *
     * @var non-empty-string
     */
    public readonly string $clePubliqueDeLApplication;

    /** @var non-empty-string */
    private readonly string $clePriveeDApple;

    /** @var non-empty-string */
    private readonly string $clePubliqueDApple;

    private readonly MockHandler $reponses;

    /**
     * Les requêtes parties vers Apple, dans l'ordre.
     *
     * @var list<RequestInterface>
     */
    private array $requetes = [];

    public function __construct()
    {
        [$this->clePriveeDeLApplication, $this->clePubliqueDeLApplication] = self::tirerUneCle([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        [$this->clePriveeDApple, $this->clePubliqueDApple] = self::tirerUneCle([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        $this->reponses = new MockHandler();
    }

    /**
     * Configure Apple comme la production : le Services ID et le trio de
     * signature, sans secret client posé.
     */
    public function configurer(): void
    {
        $pile = HandlerStack::create($this->reponses);
        $pile->push(Middleware::mapRequest(function (RequestInterface $requete): RequestInterface {
            $this->requetes[] = $requete;

            return $requete;
        }));

        config([
            'services.apple.client_id' => self::SERVICES_ID,
            'services.apple.client_secret' => null,
            'services.apple.team_id' => self::EQUIPE,
            'services.apple.key_id' => self::IDENTIFIANT_DE_CLE,
            'services.apple.private_key' => $this->clePriveeDeLApplication,
            'services.apple.guzzle' => ['handler' => $pile],
        ]);

        Cache::put('socialite:Apple-JWKSet', ['keys' => [$this->cleDAppleEnJwk()]], 300);
    }

    /**
     * Ce qu'Octane fait entre deux requêtes, et que la suite de tests garderait
     * sinon : Socialite oublie ses pilotes, qui retiennent la requête qui les a
     * créés ; la garde oublie l'utilisateur ; la session repart vide, comme
     * pour le POST d'Apple, qui arrive sans le cookie de session.
     */
    public function nouvelleRequete(): void
    {
        $socialite = app(Factory::class);

        if (! $socialite instanceof SocialiteManager) {
            throw new RuntimeException('Socialite n’est pas son gestionnaire habituel.');
        }

        $socialite->forgetDrivers();
        app('auth')->forgetGuards();
        app('cookie')->flushQueuedCookies();
        app('session')->driver()->flush();
        app('session')->driver()->regenerate();
    }

    /**
     * Le jeton d'identité qu'Apple signerait pour ce nonce.
     *
     * @param  array<string, string|bool>  $revendications
     */
    public function jetonDIdentite(string $nonce, array $revendications = []): string
    {
        $configuration = Configuration::forAsymmetricSigner(
            new Rs256(),
            InMemory::plainText($this->clePriveeDApple),
            InMemory::plainText($this->clePubliqueDApple),
        );
        $maintenant = new DateTimeImmutable();

        $constructeur = $configuration->builder()
            ->issuedBy('https://appleid.apple.com')
            ->permittedFor(self::SERVICES_ID)
            ->relatedTo('001234.apple-de-test.0042')
            ->issuedAt($maintenant)
            ->expiresAt($maintenant->modify('+10 minutes'))
            ->withHeader('kid', self::IDENTIFIANT_DE_CLE_D_APPLE)
            ->withClaim('nonce', $nonce);

        foreach (['email' => 'nouveau@example.org', 'email_verified' => 'true', ...$revendications] as $nom => $valeur) {
            $constructeur = $constructeur->withClaim(self::nonVide($nom), $valeur);
        }

        return $constructeur->getToken($configuration->signer(), $configuration->signingKey())->toString();
    }

    /**
     * La prochaine réponse du point d'échange d'Apple.
     */
    public function repondraParLeJeton(string $jetonDIdentite): void
    {
        $this->reponses->append(new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'access_token' => 'jeton-d-acces-de-test',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => 'jeton-de-rafraichissement-de-test',
            'id_token' => $jetonDIdentite,
        ])));
    }

    /**
     * @return list<RequestInterface>
     */
    public function requetesParties(): array
    {
        return $this->requetes;
    }

    /**
     * Le secret client que l'application a présenté à Apple, tiré de son
     * en-tête `Authorization: Basic`.
     *
     * @return non-empty-string
     */
    public function secretClientPresente(RequestInterface $requete): string
    {
        $entete = $requete->getHeaderLine('Authorization');
        $identifiants = base64_decode(substr($entete, strlen('Basic ')), true);

        if (! str_starts_with($entete, 'Basic ') || ! is_string($identifiants) || ! str_contains($identifiants, ':')) {
            throw new RuntimeException('L’échange ne présente pas d’identifiants Basic.');
        }

        [$client, $secret] = explode(':', $identifiants, 2);

        if ($client !== self::SERVICES_ID) {
            throw new RuntimeException("L’échange se présente comme {$client}.");
        }

        return self::nonVide($secret);
    }

    /**
     * Le nonce qu'une redirection envoie à Apple.
     */
    public static function nonceEnvoyePar(string $adresse): string
    {
        parse_str((string) parse_url($adresse, PHP_URL_QUERY), $parametres);
        $nonce = $parametres['nonce'] ?? null;

        if (! is_string($nonce) || $nonce === '') {
            throw new RuntimeException('La redirection n’envoie pas de nonce à Apple.');
        }

        return $nonce;
    }

    /**
     * La clé publique d'Apple, comme `https://appleid.apple.com/auth/keys` la
     * publie.
     *
     * @return array{kty: string, kid: string, use: string, alg: string, n: string, e: string}
     */
    private function cleDAppleEnJwk(): array
    {
        $cle = openssl_pkey_get_public($this->clePubliqueDApple);
        $details = $cle === false ? false : openssl_pkey_get_details($cle);

        if (! is_array($details) || ! is_array($details['rsa'] ?? null)) {
            throw new RuntimeException('La clé d’Apple de test ne se relit pas.');
        }

        $base64Url = static fn (mixed $binaire): string => rtrim(strtr(base64_encode(is_string($binaire) ? $binaire : ''), '+/', '-_'), '=');

        return [
            'kty' => 'RSA',
            'kid' => self::IDENTIFIANT_DE_CLE_D_APPLE,
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => $base64Url($details['rsa']['n'] ?? null),
            'e' => $base64Url($details['rsa']['e'] ?? null),
        ];
    }

    /**
     * Une paire de clés neuve, en PEM.
     *
     * @param  array<string, int|string>  $options
     * @return array{0: non-empty-string, 1: non-empty-string}
     */
    private static function tirerUneCle(array $options): array
    {
        $cle = openssl_pkey_new($options);

        if ($cle === false || ! openssl_pkey_export($cle, $privee)) {
            throw new RuntimeException('OpenSSL ne tire pas de clé de test.');
        }

        $details = openssl_pkey_get_details($cle);

        if (! is_array($details) || ! is_string($details['key'] ?? null) || ! is_string($privee)) {
            throw new RuntimeException('La clé de test n’a pas de moitié publique.');
        }

        return [self::nonVide($privee), self::nonVide($details['key'])];
    }

    /**
     * @return non-empty-string
     */
    private static function nonVide(string $texte): string
    {
        if ($texte === '') {
            throw new RuntimeException('Une clé ou une revendication vide.');
        }

        return $texte;
    }
}

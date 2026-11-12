<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * Le vrai Redis des tests du groupe `redis` (tests/Feature/Redis, #2151).
 *
 * La suite ordinaire tourne sur `array` et `sync` (phpunit.xml) : elle ne
 * voit ni éviction, ni verrou, ni file, ni limiteur réels, et `LecteurDeRedis`
 * n'y lit qu'une réponse écrite (`RedisQuiRepond`). Les tests de ce groupe
 * parlent à un serveur : la CI leur donne un service à l'image de la
 * production, dans une étape à part (`.github/workflows/ci.yml`), et
 * phpunit.xml les écarte de toute autre passe.
 *
 * `tests/Pest.php` range chaque test du dossier au groupe, et
 * `Tests\TestCase::setUpTraits()` appelle, pour un test du groupe,
 * `exiger()` avant `RefreshDatabase`, puis `vider()` une fois la base migrée
 * et à la fin du test : un test du groupe lancé sans vrai Redis échoue en le
 * disant, avant toute migration, jamais en passant sur un magasin `array`.
 * Chaque test part de deux bases vides et de compteurs remis à zéro : le
 * serveur doit être jetable : `exiger()` ne l'admet que sur la boucle locale
 * (le service de la CI) ou quand `REDIS_REEL_JETABLE=1` le déclare : le Redis
 * de développement du conteneur `redis` n'y passe que déclaré jetable, car
 * `FLUSHDB` le viderait.
 */
final class RedisReel
{
    /** Le groupe Pest des tests qui parlent à un vrai Redis. */
    public const string GROUPE = 'redis';

    /**
     * Ce que le groupe exige de la configuration : chaque magasin sur Redis,
     * comme en production (`x-app-env` de `docker-compose.prod.yml`).
     *
     * @var array<string, string>
     */
    private const array PILOTES = [
        'cache.default' => 'redis',
        'session.driver' => 'redis',
        'queue.default' => 'redis',
    ];

    /** La variable qui déclare jetable un serveur hors de la boucle locale. */
    public const string VARIABLE_DU_SERVEUR_JETABLE = 'REDIS_REEL_JETABLE';

    /** @var list<string> */
    private const array CONNEXIONS = ['default', 'cache'];

    /** Vrai une fois `exiger()` passé : `vider()` n'a rien à vider sinon. */
    private static bool $exige = false;

    /**
     * Pourquoi le groupe ne peut pas tourner ici, ou `null` quand un vrai
     * Redis répond sur les deux connexions, chacune sur sa base.
     */
    public static function raisonDeNePasTourner(): ?string
    {
        return self::raisonDuClient() ?? self::raisonDesPilotes() ?? self::raisonDesBases() ?? self::raisonDuServeur() ?? self::raisonDesConnexions();
    }

    /**
     * Fait échouer le test, en disant pourquoi, quand le groupe ne peut pas
     * tourner. Ne lit que la configuration et le serveur :
     * `Tests\TestCase::setUpTraits()` l'appelle avant que `RefreshDatabase` ne
     * migre la base, qui touche déjà le magasin du cache.
     */
    public static function exiger(): void
    {
        self::$exige = false;
        $raison = self::raisonDeNePasTourner();

        if ($raison !== null) {
            Assert::fail(sprintf(
                'Ce test du groupe redis exige un vrai Redis : %s. Le lancer par `php artisan test --group=redis`, avec CACHE_STORE, SESSION_DRIVER et QUEUE_CONNECTION à redis et REDIS_HOST vers un Redis jetable, que chaque test vide (.ai/rules/tests.md).',
                $raison,
            ));
        }

        self::$exige = true;
    }

    /**
     * Vide les bases des deux connexions et remet à zéro les compteurs
     * d'`INFO` (évictions, refus, réussites du cache), quand `exiger()` est
     * passé.
     */
    public static function vider(): void
    {
        if (! self::$exige) {
            return;
        }

        foreach (self::CONNEXIONS as $connexion) {
            Redis::connection($connexion)->flushdb();
        }

        Redis::connection('default')->command('config', ['RESETSTAT']);
    }

    /**
     * Lance `$quoi` sous les réglages donnés du serveur (`CONFIG SET`), puis
     * rend ceux d'avant, même quand `$quoi` échoue.
     *
     * @template T
     *
     * @param  array<string, string>  $reglages
     * @param  Closure(): T  $quoi
     * @return T
     */
    public static function sousLesReglages(array $reglages, Closure $quoi): mixed
    {
        $connexion = Redis::connection('default');
        $avant = [];

        try {
            foreach ($reglages as $nom => $valeur) {
                $lu = $connexion->command('config', ['GET', $nom]);
                $avant[$nom] = is_array($lu) && is_scalar($lu[$nom] ?? null) ? (string) $lu[$nom] : '';
                $connexion->command('config', ['SET', $nom, $valeur]);
            }

            return $quoi();
        } finally {
            foreach ($avant as $nom => $valeur) {
                $connexion->command('config', ['SET', $nom, $valeur]);
            }
        }
    }

    /**
     * Le nom complet d'une clé de la connexion donnée, préfixe du client
     * compris, tel que le serveur le range.
     */
    public static function cleComplete(string $cle): string
    {
        $prefixe = config('database.redis.options.prefix');

        return (is_string($prefixe) ? $prefixe : '').$cle;
    }

    private static function raisonDuClient(): ?string
    {
        $client = config('database.redis.client');

        if ($client === 'phpredis') {
            return extension_loaded('redis') ? null : 'l\'extension redis (phpredis) n\'est pas chargée';
        }

        if ($client === 'predis') {
            return class_exists('Predis\\Client') ? null : 'predis n\'est pas installé';
        }

        return sprintf('le client « %s » n\'est ni phpredis ni predis', is_scalar($client) ? (string) $client : '?');
    }

    private static function raisonDesPilotes(): ?string
    {
        foreach (self::PILOTES as $cle => $attendu) {
            $valeur = config($cle);

            if ($valeur !== $attendu) {
                return sprintf('%s vaut « %s » au lieu de « %s »', $cle, is_scalar($valeur) ? (string) $valeur : '?', $attendu);
            }
        }

        return null;
    }

    /**
     * Un `FLUSHDB` sur le Redis de développement emporterait ses sessions, son
     * cache et sa file : le serveur doit être sur la boucle locale, ou déclaré
     * jetable.
     */
    public static function raisonDuServeur(): ?string
    {
        if (filter_var(getenv(self::VARIABLE_DU_SERVEUR_JETABLE), FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        foreach (self::CONNEXIONS as $connexion) {
            $url = config("database.redis.{$connexion}.url");

            if (is_string($url) && $url !== '') {
                return sprintf('la connexion %s est donnée par une URL (REDIS_URL), qui l\'emporte sur l\'hôte, et %s n\'est pas posé : le groupe vide les bases du serveur', $connexion, self::VARIABLE_DU_SERVEUR_JETABLE);
            }

            $hote = config("database.redis.{$connexion}.host");

            if (! in_array($hote, ['127.0.0.1', 'localhost', '::1'], true)) {
                return sprintf(
                    'la connexion %s vise « %s », hors de la boucle locale, et %s n\'est pas posé : le groupe vide les bases du serveur',
                    $connexion,
                    is_scalar($hote) ? (string) $hote : '?',
                    self::VARIABLE_DU_SERVEUR_JETABLE,
                );
            }
        }

        return null;
    }

    /**
     * `cache:clear` vide la base de la connexion `cache` : partagée avec
     * `default`, elle emporterait ce que les tests veulent voir survivre.
     */
    private static function raisonDesBases(): ?string
    {
        $donnees = config('database.redis.default.database');
        $cache = config('database.redis.cache.database');

        if (is_scalar($donnees) && is_scalar($cache) && (string) $donnees !== (string) $cache) {
            return null;
        }

        return sprintf('les connexions default et cache partagent la base %s', is_scalar($donnees) ? (string) $donnees : '?');
    }

    private static function raisonDesConnexions(): ?string
    {
        foreach (self::CONNEXIONS as $connexion) {
            try {
                Redis::connection($connexion)->command('ping');
            } catch (Throwable $erreur) {
                $hote = config("database.redis.{$connexion}.host");
                $port = config("database.redis.{$connexion}.port");

                return sprintf(
                    'la connexion %s (%s:%s) ne répond pas (%s)',
                    $connexion,
                    is_scalar($hote) ? (string) $hote : '?',
                    is_scalar($port) ? (string) $port : '?',
                    $erreur->getMessage(),
                );
            }
        }

        return null;
    }
}

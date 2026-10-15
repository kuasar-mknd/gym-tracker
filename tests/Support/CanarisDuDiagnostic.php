<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Les canaris du diagnostic (#2032) : des valeurs qu'aucune sortie du
 * diagnostic ne doit laisser passer, semées par les vrais chemins de
 * l'application (une écriture refusée, un job qui échoue, une requête mal
 * formée dans une tâche planifiée, une erreur navigateur).
 *
 * Les uns ont une forme que l'expurgation reconnaît (un courriel, une
 * adresse IP, un secret, un hôte de courrier) : on peut les écrire dans un
 * message, que `TexteBrut` montre expurgé. Les autres n'en ont pas (une
 * phrase avec apostrophe, un nom propre, un code) : rien ne les distingue
 * d'un texte ordinaire, et c'est le fait de ne pas rendre la colonne qui
 * les tient, ou la réduction d'un message SQL. Ceux-là ne vont donc que là
 * où le diagnostic ne lit pas : valeurs d'une requête SQL, charge utile,
 * corps, en-têtes, chaîne de requête, chaîne d'agent, sortie d'une commande.
 */
final class CanarisDuDiagnostic
{
    public const string COURRIEL = 'canari.dupont@example.org';

    public const string ADRESSE_IP = '203.0.113.7';

    public const string SECRET = 'c0ffee5ca1ab1e0ddba11deadbeefcafe0123456';

    public const string HOTE_DU_COURRIER = 'courrier-canari.example.org';

    public const string PHRASE = "l'haltère de Bérénice";

    public const string NOM_PROPRE = 'Gwendoline Kervella';

    public const string CODE = 'XK42Q7ZP';

    /**
     * @return array<string, string>
     */
    public static function reconnaissables(): array
    {
        return [
            'courriel' => self::COURRIEL,
            'adresse IP' => self::ADRESSE_IP,
            'secret hexadécimal' => self::SECRET,
            'hôte du courrier' => self::HOTE_DU_COURRIER,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function sansForme(): array
    {
        return [
            'phrase avec apostrophe' => self::PHRASE,
            'nom propre' => self::NOM_PROPRE,
            'code alphanumérique' => self::CODE,
        ];
    }

    /**
     * Un message qui cite chaque canari reconnaissable.
     */
    public static function messageReconnaissable(): string
    {
        return sprintf(
            'Envoi refusé par %s (%s) pour %s, clé %s',
            self::HOTE_DU_COURRIER,
            self::ADRESSE_IP,
            self::COURRIEL,
            self::SECRET,
        );
    }

    /**
     * Deux comptes à la même adresse : le second est refusé, et ses valeurs
     * (la phrase, le nom propre, le code dans l'adresse) sont celles de
     * l'écriture refusée, que le message d'erreur SQL recopie quand rien ne
     * les tait.
     */
    public static function ecrireUnCompteEnDouble(string $premier = self::NOM_PROPRE, string $second = self::PHRASE): void
    {
        DB::table('users')->insert(['name' => $premier, 'email' => self::adresseEnDouble()]);
        DB::table('users')->insert(['name' => $second, 'email' => self::adresseEnDouble()]);
    }

    /**
     * Une requête écrite à la main, valeurs comprises, que le serveur refuse
     * dès son premier mot. Son texte cite la requête à partir de l'erreur
     * (« near '…' »), et le moniteur des tâches planifiées coupe le message à
     * 255 caractères : au milieu de l'adresse, dont la citation reste
     * ouverte. Les valeurs sont dans le texte de la requête, pas dans ses
     * liaisons : seul le masque du texte du serveur, quand la connexion le
     * porte, les tait.
     */
    public static function ecrireUneRequeteMalFormee(): void
    {
        DB::update(sprintf("updat users set name = '%s', email = '%s'", self::NOM_PROPRE, self::adresseEnDouble()));
    }

    /**
     * L'adresse du compte en double : le code, et rien de reconnaissable que
     * le domaine.
     */
    public static function adresseEnDouble(): string
    {
        return mb_strtolower(self::CODE).'@example.org';
    }

    /**
     * Le nom de chaque canari trouvé dans le texte donné, entités HTML
     * décodées, sans tenir compte de la casse.
     *
     * @return list<string>
     */
    public static function trouvesDans(string $texte): array
    {
        $lu = mb_strtolower(html_entity_decode($texte, ENT_QUOTES | ENT_HTML5));
        $canaris = [...self::reconnaissables(), ...self::sansForme(), 'adresse du compte en double' => self::adresseEnDouble()];

        return array_keys(array_filter($canaris, static fn (string $canari): bool => str_contains($lu, mb_strtolower($canari))));
    }
}

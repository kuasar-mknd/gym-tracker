<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Expurgation;

/**
 * Septième étape de l'expurgation : ce qui ressemble à un secret devient
 * `[secret]`.
 *
 * `Bearer …`, le jeton de diagnostic (`gtdiag_…`), la valeur qui suit un nom
 * de secret (`key`, `token`, `password`… avant `:` ou `=`, quelle que soit
 * sa forme : un secret en base64 à barres obliques n'en a aucune), les JWT,
 * les suites de 40
 * caractères ou plus en base64 : sans `/`, quand elles mêlent minuscules,
 * majuscules et chiffres, comme un secret tiré au hasard et à la différence
 * d'un nom de migration ; avec `/`, seulement quand elles portent un `+` ou
 * finissent par `=`, pour qu'un chemin (`app/Http/Controllers/Api/V1/…`) ne
 * soit pas pris pour un secret. Puis les suites hexadécimales de 32
 * caractères ou plus (empreintes, clés, jetons), avec ou sans le préfixe
 * `0x`, quel que soit leur voisin hors d'une lettre ou d'un chiffre : le
 * jeton d'un lien de réinitialisation suit une barre oblique dans le
 * chemin, une clé suit un `=`. Elles passent après le base64, qui couvre
 * ainsi en entier un secret où une telle suite figure. Chaque motif ne
 * s'essaie qu'au début d'un mot : une longue suite n'est parcourue qu'une
 * fois.
 *
 * Les deux premiers motifs sont publics : la table des exceptions et le
 * journal masquent aussi, par valeur, le jeton de diagnostic et la valeur
 * d'un en-tête `Bearer …` (`TexteSansJeton`), et la garde des fichiers
 * suivis reconnaît un jeton au même motif
 * (`AucunJetonDeDiagnosticNEstSuiviTest`). Ils ne s'écrivent qu'ici.
 */
final class MasqueLesSecrets
{
    /**
     * La valeur d'un en-tête `Authorization: Bearer …` : le mot, quelle que
     * soit sa casse, un à huit blancs, puis ce qui suit jusqu'au premier
     * blanc, guillemet, apostrophe ou chevron, 4 096 octets au plus. Le motif
     * lit des octets. La table des exceptions et le journal retirent
     * eux-mêmes la suite d'une valeur plus longue (`TexteSansJeton`).
     */
    public const string MOTIF_BEARER = '/\bBearer\s{1,8}[^\s"\'<>]{1,4096}/i';

    /**
     * Le jeton de diagnostic (#2032) : `gtdiag_`, un identifiant de douze
     * caractères `[a-z0-9]`, `_`, un secret de quarante-huit caractères
     * `[A-Za-z0-9]`.
     */
    public const string MOTIF_DU_JETON = '/gtdiag_[a-z0-9]{12}_[A-Za-z0-9]{48}/';

    /**
     * La valeur qui suit un nom de secret (`key`, `secret`, `token`,
     * `jeton`, `password`, `passwd`, `pwd`, `signature`, précédé de 32
     * caractères de mot au plus, `AWS_SECRET_ACCESS_KEY`), après `:` ou `=`,
     * entre guillemets ou non : le nom désigne un secret que sa forme ne
     * désigne pas.
     */
    private const string MOTIF_D_UN_NOM_DE_SECRET = '/(?<![A-Za-z0-9])[A-Za-z0-9_-]{0,32}(?:key|secret|token|jeton|password|passwd|pwd|signature)["\']?\s{0,4}[:=]\s{0,4}["\']?\K[^\s"\'<>,;&)]{8,4096}/i';

    /**
     * Chaque motif et sa marque, dans l'ordre où ils s'appliquent.
     *
     * @var array<string, string>
     */
    private const array MOTIFS = [
        self::MOTIF_BEARER => 'Bearer [secret]',
        self::MOTIF_DU_JETON => '[secret]',
        self::MOTIF_D_UN_NOM_DE_SECRET => '[secret]',
        '/(?<![\w-])eyJ[\w-]{2,4096}\.[\w-]{2,4096}\.[\w-]{0,4096}/' => '[secret]',
        '/(?<![\w+\/-])(?=[\w+-]{0,4096}[a-z])(?=[\w+-]{0,4096}[A-Z])(?=[\w+-]{0,4096}\d)[\w+-]{40,4096}={0,2}(?![\w+\/=-])/' => '[secret]',
        '/(?<![\w+\/-])(?=[\w+\/-]{0,4096}(?:\+|=))[\w+\/-]{40,4096}={0,2}(?![\w+\/=-])/' => '[secret]',
        '/(?<![0-9A-Za-z])(?:0x)?[0-9a-f]{32,4096}(?![0-9A-Za-z])/i' => '[secret]',
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

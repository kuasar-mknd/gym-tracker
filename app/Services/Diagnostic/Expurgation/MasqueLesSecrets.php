<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Expurgation;

/**
 * Septième étape de l'expurgation : ce qui ressemble à un secret devient
 * `[secret]`.
 *
 * `Bearer …`, le jeton de diagnostic (`gtdiag_…`), les JWT, les suites de 40
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
 */
final class MasqueLesSecrets
{
    /**
     * Chaque motif et sa marque, dans l'ordre où ils s'appliquent.
     *
     * @var array<string, string>
     */
    private const array MOTIFS = [
        '/\bBearer\s{1,8}[^\s"\'<>]{1,4096}/i' => 'Bearer [secret]',
        '/gtdiag_[a-z0-9]{12}_[A-Za-z0-9]{48}/' => '[secret]',
        '/(?<![\w-])eyJ[\w-]{2,4096}\.[\w-]{2,4096}\.[\w-]{0,4096}/' => '[secret]',
        '/(?<![\w+\/=-])(?=[\w+-]{0,4096}[a-z])(?=[\w+-]{0,4096}[A-Z])(?=[\w+-]{0,4096}\d)[\w+-]{40,4096}={0,2}(?![\w+\/=-])/' => '[secret]',
        '/(?<![\w+\/=-])(?=[\w+\/-]{0,4096}(?:\+|=))[\w+\/-]{40,4096}={0,2}(?![\w+\/=-])/' => '[secret]',
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

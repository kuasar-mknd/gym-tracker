<?php

declare(strict_types=1);

return [
    'preset' => 'laravel',
    'ide' => 'vscode',
    'exclude' => [
        '_ide_helper.php',
        '_ide_helper_models.php',
        'bootstrap/cache',
        'storage',
        'public',
    ],
    'add' => [
        // Add specific insights
    ],
    'remove' => [
        // Remove rules that conflict with Laravel Pint
        \SlevomatCodingStandard\Sniffs\Classes\EmptyLinesAroundClassBracesSniff::class,
        \SlevomatCodingStandard\Sniffs\Commenting\DocCommentSpacingSniff::class,
        \PhpCsFixer\Fixer\Basic\BracesFixer::class,
        \PHP_CodeSniffer\Standards\PSR2\Sniffs\Methods\FunctionClosingBraceSniff::class,
        \PHP_CodeSniffer\Standards\Generic\Sniffs\Formatting\SpaceAfterNotSniff::class,
        // Also remove ObjectCalisthenics if it's too strict
        // \ObjectCalisthenics\Sniffs\Files\ClassTraitAndInterfaceLengthSniff::class,

        // Allow common Laravel patterns
        \NunoMaduro\PhpInsights\Domain\Insights\ForbiddenTraits::class,
        \SlevomatCodingStandard\Sniffs\Classes\SuperfluousExceptionNamingSniff::class,
        \NunoMaduro\PhpInsights\Domain\Insights\ForbiddenNormalClasses::class,
        \NunoMaduro\PhpInsights\Domain\Insights\ForbiddenFinalClasses::class,
    ],
    'config' => [
        \SlevomatCodingStandard\Sniffs\TypeHints\PropertyTypeHintSniff::class => [
            'exclude' => [
                'app/Models/User.php',
            ],
        ],
        // Eloquent lit `withoutObjectCaching` sur le cast par son nom, en
        // propriété publique (`HasAttributes::getClassCastableAttributeValue()`) :
        // c'est ainsi qu'un cast rend une copie à chaque lecture (#2159).
        \SlevomatCodingStandard\Sniffs\Classes\ForbiddenPublicPropertySniff::class => [
            'exclude' => [
                'app/Models/Casts/InstantAvecSonDecalage.php',
            ],
        ],
        // Une surcharge de `Store::setId()`, que Laravel nomme ainsi.
        \NunoMaduro\PhpInsights\Domain\Sniffs\ForbiddenSetterSniff::class => [
            'exclude' => [
                'app/Support/Sessions/NeRenaitPasDeSaDestruction.php',
            ],
        ],
    ],
];

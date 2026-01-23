<?php

declare(strict_types=1);

return [
    'preset' => 'laravel',
    'ide' => 'vscode',
    'exclude' => [
        // Exclude specific files if necessary
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
    ],
    'config' => [
        \SlevomatCodingStandard\Sniffs\TypeHints\PropertyTypeHintSniff::class => [
            'exclude' => [
                'app/Models/User.php',
            ],
        ],
    ],
];

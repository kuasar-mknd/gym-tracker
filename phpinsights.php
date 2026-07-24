<?php

declare(strict_types=1);
use NunoMaduro\PhpInsights\Domain\Insights\ForbiddenFinalClasses;
use NunoMaduro\PhpInsights\Domain\Insights\ForbiddenNormalClasses;
use NunoMaduro\PhpInsights\Domain\Insights\ForbiddenTraits;
use PHP_CodeSniffer\Standards\Generic\Sniffs\Formatting\SpaceAfterNotSniff;
use PHP_CodeSniffer\Standards\PSR2\Sniffs\Methods\FunctionClosingBraceSniff;
use PhpCsFixer\Fixer\Basic\BracesFixer;
use SlevomatCodingStandard\Sniffs\Classes\EmptyLinesAroundClassBracesSniff;
use SlevomatCodingStandard\Sniffs\Classes\SuperfluousExceptionNamingSniff;
use SlevomatCodingStandard\Sniffs\Commenting\DocCommentSpacingSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\PropertyTypeHintSniff;

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
        EmptyLinesAroundClassBracesSniff::class,
        DocCommentSpacingSniff::class,
        BracesFixer::class,
        FunctionClosingBraceSniff::class,
        SpaceAfterNotSniff::class,
        // Also remove ObjectCalisthenics if it's too strict
        // \ObjectCalisthenics\Sniffs\Files\ClassTraitAndInterfaceLengthSniff::class,

        // Allow common Laravel patterns
        ForbiddenTraits::class,
        SuperfluousExceptionNamingSniff::class,
        ForbiddenNormalClasses::class,
        ForbiddenFinalClasses::class,
    ],
    'config' => [
        PropertyTypeHintSniff::class => [
            'exclude' => [
                'app/Models/User.php',
            ],
        ],
    ],
];

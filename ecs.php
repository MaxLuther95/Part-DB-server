<?php

declare(strict_types=1);

use Symplify\EasyCodingStandard\Config\ECSConfig;
use Symplify\EasyCodingStandard\ValueObject\Set\SetList;
use Symplify\CodingStandard\Fixer\LineLength\LineLengthFixer;

return ECSConfig::configure()
    ->withSets([
        SetList::CLEAN_CODE,
        SetList::PSR_12,
        SetList::COMMON,
    ])
    ->withPhpCsFixerSets(symfony: true)
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        LineLengthFixer::class,
    ]);

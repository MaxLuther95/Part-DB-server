<?php

declare(strict_types=1);

namespace App\Twig;

use App\Services\Production\ProductionTreeBuilder;
use Twig\Attribute\AsTwigFunction;

final readonly class ProductionNavigationExtension
{
    public function __construct(private ProductionTreeBuilder $treeBuilder)
    {
    }

    #[AsTwigFunction('production_navigation_available')]
    public function isAvailable(): bool
    {
        return $this->treeBuilder->hasVisibleEntries();
    }
}

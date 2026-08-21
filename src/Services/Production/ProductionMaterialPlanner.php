<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Entity\Parts\Part;
use App\Entity\Production\CustomerProject;

final class ProductionMaterialPlanner
{
    /**
     * @return array{items: list<array{part: Part, required: float, allocated: float, remaining: float, available: float, missing: float}>, complete: bool}
     */
    public function createPlan(CustomerProject $project): array
    {
        /** @var array<int, array{part: Part, required: float}> $requirements */
        $requirements = [];

        foreach ($project->getPositions() as $position) {
            $templateProject = $position->getBuildProject();
            if (null === $templateProject) {
                continue;
            }
            foreach ($templateProject->getBomEntries() as $entry) {
                $part = $entry->getPart();
                if (!$part instanceof Part) {
                    continue;
                }
                $partId = $part->getId();
                if (null === $partId) {
                    continue;
                }
                $requirements[$partId] ??= ['part' => $part, 'required' => 0.0];
                $requirements[$partId]['required'] += $entry->getQuantity() * $position->getQuantity();
            }
        }

        foreach ($project->getAccessories() as $accessory) {
            $part = $accessory->getPart();
            if (!$part instanceof Part || null === $part->getId()) {
                continue;
            }
            $partId = $part->getId();
            $requirements[$partId] ??= ['part' => $part, 'required' => 0.0];
            $requirements[$partId]['required'] += $accessory->getQuantity();
        }

        /** @var array<int, float> $allocatedByPart */
        $allocatedByPart = [];
        foreach ($project->getMaterialAllocations() as $allocation) {
            $partId = $allocation->getPart()?->getId();
            if (null !== $partId) {
                $allocatedByPart[$partId] = ($allocatedByPart[$partId] ?? 0.0) + $allocation->getQuantity();
            }
        }

        $items = [];
        $complete = true;
        foreach ($requirements as $partId => $requirement) {
            $allocated = $allocatedByPart[$partId] ?? 0.0;
            $remaining = max(0.0, $requirement['required'] - $allocated);
            $available = $requirement['part']->getAmountSum();
            $missing = max(0.0, $remaining - $available);
            $complete = $complete && 0.0 === $missing;
            $items[] = [
                'part' => $requirement['part'],
                'required' => $requirement['required'],
                'allocated' => $allocated,
                'remaining' => $remaining,
                'available' => $available,
                'missing' => $missing,
            ];
        }

        usort($items, static fn(array $left, array $right): int => strcasecmp($left['part']->getName(), $right['part']->getName()));

        return ['items' => $items, 'complete' => $complete];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Entity\Parts\Part;
use App\Entity\Production\CustomerProject;
use App\Entity\Production\ProjectPosition;
use App\Repository\Production\ProjectMaterialReservationRepository;

final readonly class ProductionMaterialPlanner
{
    public function __construct(private ProjectMaterialReservationRepository $reservationRepository)
    {
    }

    /** @return array<int, array{part: Part, required: int}> */
    public function getRequirements(CustomerProject $project): array
    {
        $requirements = [];
        foreach ($project->getPositions() as $position) {
            $templateProject = $position->getBuildProject();
            if (null === $templateProject) { continue; }
            foreach ($templateProject->getBomEntries() as $entry) {
                $part = $entry->getPart();
                if (!$part instanceof Part || null === $part->getId()) { continue; }
                $partId = $part->getId();
                $requirements[$partId] ??= ['part' => $part, 'required' => 0.0];
                $requirements[$partId]['required'] += $entry->getQuantity() * $position->getQuantity();
            }
        }
        foreach ($project->getAccessories() as $accessory) {
            $part = $accessory->getPart();
            if (!$part instanceof Part || null === $part->getId()) { continue; }
            $partId = $part->getId();
            $requirements[$partId] ??= ['part' => $part, 'required' => 0.0];
            $requirements[$partId]['required'] += $accessory->getQuantity();
        }

        return array_map(static fn(array $row): array => ['part' => $row['part'], 'required' => (int) ceil($row['required'])], $requirements);
    }

    /** @return array{items: list<array<string, mixed>>, complete: bool, configuration_complete: bool, fully_reserved: bool, reservation_stale: bool, reservation_conflict: bool} */
    public function createPlan(CustomerProject $project): array
    {
        $requirements = $this->getRequirements($project);
        $configurationComplete = $this->isConfigurationComplete($project);
        $allocated = [];
        foreach ($project->getMaterialAllocations() as $allocation) {
            $partId = $allocation->getPart()?->getId();
            if (null !== $partId) { $allocated[$partId] = ($allocated[$partId] ?? 0) + $allocation->getQuantity(); }
        }
        $consumed = [];
        foreach ($project->getBuildInstances() as $instance) {
            foreach ($instance->getMaterialUsages() as $usage) {
                $partId = $usage->getPart()?->getId();
                if (null !== $partId) { $consumed[$partId] = ($consumed[$partId] ?? 0) + $usage->getQuantity(); }
            }
        }
        $reserved = [];
        $reservationConflict = false;
        $orphanedReservation = false;
        foreach ($project->getMaterialReservations() as $reservation) {
            $partId = $reservation->getPart()?->getId();
            $lot = $reservation->getSourcePartLot();
            if (null === $partId || null === $lot) {
                $orphanedReservation = true;
                $reservationConflict = true;
                continue;
            }
            $reserved[$partId] = ($reserved[$partId] ?? 0) + $reservation->getQuantity();
            if ($this->reservationRepository->quantityForLot($lot) > (int) floor($lot->getAmount())) {
                $reservationConflict = true;
            }
            $requirements[$partId] ??= ['part' => $reservation->getPart(), 'required' => 0];
        }

        $items = [];
        $complete = $configurationComplete;
        $fullyReserved = true;
        $reservationStale = $orphanedReservation;
        foreach ($requirements as $partId => $requirement) {
            $part = $requirement['part'];
            $required = $requirement['required'];
            $projectStock = $allocated[$partId] ?? 0;
            $used = $consumed[$partId] ?? 0;
            $ownReserved = $reserved[$partId] ?? 0;
            $remaining = max(0, $required - $projectStock - $used);
            $unreservedRemaining = max(0, $remaining - $ownReserved);
            $physical = (int) floor($part->getAmountSum());
            $reservedTotal = $this->reservationRepository->quantityForPart($part);
            $freeAvailable = max(0, $physical - $reservedTotal);
            $missing = max(0, $unreservedRemaining - $freeAvailable);
            $overstock = max(0, $projectStock + $used + $ownReserved - $required);
            $stale = $ownReserved !== $remaining;
            $reservationStale = $reservationStale || $stale;
            $fullyReserved = $fullyReserved && !$stale;
            $complete = $complete && 0 === $missing && !$reservationConflict;
            $items[] = [
                'part' => $part,
                'required' => $required,
                'allocated' => $projectStock,
                'consumed' => $used,
                'reserved' => $ownReserved,
                'secured' => $projectStock + $ownReserved,
                'reserved_total' => $reservedTotal,
                'remaining' => $remaining,
                'unreserved_remaining' => $unreservedRemaining,
                'physical' => $physical,
                'available' => $freeAvailable,
                'missing' => $missing,
                'overstock' => $overstock,
                'reservation_stale' => $stale,
            ];
        }
        usort($items, static fn(array $left, array $right): int => strcasecmp($left['part']->getName(), $right['part']->getName()));

        return ['items' => $items, 'complete' => $complete, 'configuration_complete' => $configurationComplete, 'fully_reserved' => $fullyReserved && !$orphanedReservation, 'reservation_stale' => $reservationStale, 'reservation_conflict' => $reservationConflict];
    }

    public function isConfigurationComplete(CustomerProject $project): bool
    {
        if ($project->getPositions()->isEmpty()) {
            return false;
        }

        foreach ($project->getPositions() as $position) {
            $template = $position->getSystemTemplate();
            if (null === $template) {
                continue;
            }

            foreach ($template->getSlots() as $slot) {
                if (!$slot->isRequired()) {
                    continue;
                }

                $assignedQuantity = array_sum(array_map(
                    static fn(ProjectPosition $assignment): int => $assignment->getQuantity(),
                    $position->getAssignmentsForSlot($slot),
                ));
                $assignedQuantity += $position->getPartAssignmentForSlot($slot)?->getQuantity() ?? 0;
                if ($assignedQuantity < $slot->getMinQuantity()) {
                    return false;
                }
            }
        }

        return true;
    }
}

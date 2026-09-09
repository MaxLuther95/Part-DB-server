<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\BuildMaterialUsage;
use App\Entity\Production\ProjectAccessory;
use App\Entity\Production\ProjectPosition;
use App\Entity\Production\SystemTemplate;
use App\Entity\Production\SystemTemplateSlot;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Saves a template slot using insertion semantics for its position.
 *
 * The database deliberately keeps its unique constraint for
 * (system_template_id, position). Existing slots are therefore moved to
 * temporary positions before their final positions are written.
 */
final readonly class SystemTemplateSlotPositioner
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function getNextPosition(SystemTemplate $template): int
    {
        $highestPosition = -1;
        foreach ($this->findSlots($template) as $slot) {
            $highestPosition = max($highestPosition, $slot->getPosition());
        }

        return $highestPosition + 1;
    }

    public function save(SystemTemplateSlot $slot, ?int $previousPosition): void
    {
        $template = $slot->getSystemTemplate();
        if (null === $template) {
            throw new \LogicException('A system template slot must belong to a system template.');
        }

        $targetPosition = $slot->getPosition();

        $this->entityManager->wrapInTransaction(function () use ($slot, $template, $previousPosition, $targetPosition): void {
            $persistedSlots = $this->findSlots($template);

            /** @var array<int, int> $finalPositions */
            $finalPositions = [];
            $requiresReordering = false;

            foreach ($persistedSlots as $persistedSlot) {
                if ($persistedSlot === $slot) {
                    continue;
                }

                $position = $persistedSlot->getPosition();
                if (null === $previousPosition) {
                    if ($position >= $targetPosition) {
                        ++$position;
                    }
                } elseif ($targetPosition < $previousPosition) {
                    if ($position >= $targetPosition && $position < $previousPosition) {
                        ++$position;
                    }
                } elseif ($targetPosition > $previousPosition) {
                    if ($position > $previousPosition && $position <= $targetPosition) {
                        --$position;
                    }
                }

                $finalPositions[spl_object_id($persistedSlot)] = $position;
                $requiresReordering = $requiresReordering || $position !== $persistedSlot->getPosition();
            }

            if (null !== $previousPosition) {
                $requiresReordering = $requiresReordering || $previousPosition !== $targetPosition;
            }

            if ($requiresReordering && [] !== $persistedSlots) {
                $highestPosition = $targetPosition;
                foreach ($persistedSlots as $persistedSlot) {
                    $highestPosition = max($highestPosition, $persistedSlot->getPosition());
                }
                foreach ($finalPositions as $position) {
                    $highestPosition = max($highestPosition, $position);
                }

                $temporaryPosition = $highestPosition + count($persistedSlots) + 1;
                foreach ($persistedSlots as $persistedSlot) {
                    $persistedSlot->setPosition($temporaryPosition++);
                }
                $this->entityManager->flush();

                foreach ($persistedSlots as $persistedSlot) {
                    if ($persistedSlot === $slot) {
                        $persistedSlot->setPosition($targetPosition);
                        continue;
                    }

                    $persistedSlot->setPosition($finalPositions[spl_object_id($persistedSlot)]);
                }
            }

            $slot->setPosition($targetPosition);
            $this->entityManager->persist($slot);
            $this->entityManager->flush();
        });
    }

    /**
     * Removes a slot and closes the resulting position gap.
     *
     * Existing concrete order selections keep their order hierarchy and are
     * only detached from the removed template slot. This prevents a template
     * maintenance action from silently deleting material demand, changing the
     * physical build hierarchy or hiding the configured selection.
     *
     * @return int Number of preserved order selections
     */
    public function remove(SystemTemplateSlot $slot): int
    {
        $template = $slot->getSystemTemplate();
        if (null === $template) {
            throw new \LogicException('A system template slot must belong to a system template.');
        }

        $removedPosition = $slot->getPosition();

        return $this->entityManager->wrapInTransaction(function () use ($slot, $template, $removedPosition): int {
            /** @var list<ProjectPosition> $linkedPositions */
            $linkedPositions = $this->entityManager->getRepository(ProjectPosition::class)->findBy(
                ['sourceSlot' => $slot],
                ['id' => 'ASC'],
            );
            /** @var list<ProjectAccessory> $linkedAccessories */
            $linkedAccessories = $this->entityManager->getRepository(ProjectAccessory::class)->findBy(
                ['sourceSlot' => $slot],
                ['id' => 'ASC'],
            );
            /** @var list<BuildInstance> $installedInstances */
            $installedInstances = $this->entityManager->getRepository(BuildInstance::class)->findBy([
                'installedSlot' => $slot,
            ]);
            /** @var list<BuildMaterialUsage> $materialUsages */
            $materialUsages = $this->entityManager->getRepository(BuildMaterialUsage::class)->findBy([
                'sourceSlot' => $slot,
            ]);

            foreach ($linkedPositions as $position) {
                $position->setSourceSlot(null);
            }

            foreach ($linkedAccessories as $accessory) {
                $accessory->setSourceSlot(null);
            }
            foreach ($installedInstances as $instance) {
                // Keep the physical parent/child relation, but remove the
                // reference and index belonging to the deleted slot.
                $instance->setInstalledSlot(null);
            }
            foreach ($materialUsages as $usage) {
                $usage->setSourceSlot(null);
            }

            if ([] !== $linkedPositions || [] !== $linkedAccessories || [] !== $installedInstances || [] !== $materialUsages) {
                $this->entityManager->flush();
            }

            $followingSlots = array_values(array_filter(
                $this->findSlots($template),
                static fn(SystemTemplateSlot $candidate): bool => $candidate !== $slot
                    && $candidate->getPosition() > $removedPosition,
            ));

            $template->removeSlot($slot);
            $this->entityManager->remove($slot);
            $this->entityManager->flush();

            if ([] === $followingSlots) {
                return count($linkedPositions) + count($linkedAccessories);
            }

            /** @var array<int, int> $finalPositions */
            $finalPositions = [];
            foreach ($followingSlots as $followingSlot) {
                $finalPositions[spl_object_id($followingSlot)] = $followingSlot->getPosition() - 1;
            }

            $temporaryPosition = max(array_map(
                static fn(SystemTemplateSlot $candidate): int => $candidate->getPosition(),
                $followingSlots,
            )) + count($followingSlots) + 1;

            foreach ($followingSlots as $followingSlot) {
                $followingSlot->setPosition($temporaryPosition++);
            }
            $this->entityManager->flush();

            foreach ($followingSlots as $followingSlot) {
                $followingSlot->setPosition($finalPositions[spl_object_id($followingSlot)]);
            }
            $this->entityManager->flush();

            return count($linkedPositions) + count($linkedAccessories);
        });
    }

    /** @return list<SystemTemplateSlot> */
    private function findSlots(SystemTemplate $template): array
    {
        /** @var list<SystemTemplateSlot> $slots */
        $slots = $this->entityManager->getRepository(SystemTemplateSlot::class)->findBy(
            ['systemTemplate' => $template],
            ['position' => 'ASC', 'id' => 'ASC'],
        );

        return $slots;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Production;

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

    public function save(SystemTemplateSlot $slot, ?int $previousPosition): void
    {
        $template = $slot->getSystemTemplate();
        if (null === $template) {
            throw new \LogicException('A system template slot must belong to a system template.');
        }

        $targetPosition = $slot->getPosition();

        $this->entityManager->wrapInTransaction(function () use ($slot, $template, $previousPosition, $targetPosition): void {
            /** @var list<SystemTemplateSlot> $persistedSlots */
            $persistedSlots = $this->entityManager->getRepository(SystemTemplateSlot::class)->findBy(
                ['systemTemplate' => $template],
                ['position' => 'ASC', 'id' => 'ASC'],
            );

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
}

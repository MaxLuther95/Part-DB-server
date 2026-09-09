<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Entity\Production\CustomerProject;
use App\Entity\Production\ProjectPosition;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Saves an order position using insertion semantics among its siblings.
 */
final readonly class ProjectPositionPositioner
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function getNextPosition(CustomerProject $project, ?ProjectPosition $parent = null): int
    {
        $highestPosition = -1;
        foreach ($this->findSiblings($project, $parent) as $position) {
            $highestPosition = max($highestPosition, $position->getPosition());
        }

        return $highestPosition + 1;
    }

    public function save(ProjectPosition $position, ?int $previousPosition): void
    {
        $project = $position->getCustomerProject();
        if (!$project instanceof CustomerProject) {
            throw new \LogicException('A project position must belong to a customer project.');
        }

        $targetPosition = $position->getPosition();
        $parent = $position->getParent();

        $this->entityManager->wrapInTransaction(function () use ($position, $project, $parent, $previousPosition, $targetPosition): void {
            foreach ($this->findSiblings($project, $parent) as $sibling) {
                if ($sibling === $position) {
                    continue;
                }

                $siblingPosition = $sibling->getPosition();
                if (null === $previousPosition) {
                    if ($siblingPosition >= $targetPosition) {
                        $sibling->setPosition($siblingPosition + 1);
                    }
                } elseif ($targetPosition < $previousPosition) {
                    if ($siblingPosition >= $targetPosition && $siblingPosition < $previousPosition) {
                        $sibling->setPosition($siblingPosition + 1);
                    }
                } elseif ($targetPosition > $previousPosition
                    && $siblingPosition > $previousPosition
                    && $siblingPosition <= $targetPosition) {
                    $sibling->setPosition($siblingPosition - 1);
                }
            }

            $position->setPosition($targetPosition);
            $this->entityManager->persist($position);
            $this->entityManager->flush();
        });
    }

    /** @return list<ProjectPosition> */
    private function findSiblings(CustomerProject $project, ?ProjectPosition $parent): array
    {
        /** @var list<ProjectPosition> $positions */
        $positions = $this->entityManager->getRepository(ProjectPosition::class)->findBy(
            ['customerProject' => $project, 'parent' => $parent],
            ['position' => 'ASC', 'id' => 'ASC'],
        );

        return $positions;
    }
}

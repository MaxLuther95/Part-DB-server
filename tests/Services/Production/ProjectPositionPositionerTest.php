<?php

declare(strict_types=1);

namespace App\Tests\Services\Production;

use App\Entity\Production\CustomerProject;
use App\Entity\Production\ProjectPosition;
use App\Services\Production\ProjectPositionPositioner;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class ProjectPositionPositionerTest extends TestCase
{
    public function testAddingAtOccupiedPositionShiftsFollowingSiblingsBack(): void
    {
        $project = (new CustomerProject())->setProjectNumber('P-1');
        $first = $this->position($project, 'Eins', 0);
        $second = $this->position($project, 'Zwei', 1);
        $new = $this->position($project, 'Neu', 0);

        $positioner = new ProjectPositionPositioner($this->entityManager([$first, $second]));
        $positioner->save($new, null);

        self::assertSame(0, $new->getPosition());
        self::assertSame(1, $first->getPosition());
        self::assertSame(2, $second->getPosition());
    }

    public function testMovingPositionReordersFollowingSiblings(): void
    {
        $project = (new CustomerProject())->setProjectNumber('P-1');
        $first = $this->position($project, 'Eins', 0);
        $second = $this->position($project, 'Zwei', 1);
        $third = $this->position($project, 'Drei', 2);
        $first->setPosition(2);

        $positioner = new ProjectPositionPositioner($this->entityManager([$first, $second, $third]));
        $positioner->save($first, 0);

        self::assertSame(2, $first->getPosition());
        self::assertSame(0, $second->getPosition());
        self::assertSame(1, $third->getPosition());
    }

    public function testNextPositionIsAppendedAfterHighestSibling(): void
    {
        $project = (new CustomerProject())->setProjectNumber('P-1');
        $positions = [
            $this->position($project, 'Eins', 1),
            $this->position($project, 'Zwei', 4),
        ];

        $positioner = new ProjectPositionPositioner($this->entityManager($positions));

        self::assertSame(5, $positioner->getNextPosition($project));
    }

    /** @param list<ProjectPosition> $positions */
    private function entityManager(array $positions): EntityManagerInterface
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn($positions);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(ProjectPosition::class)->willReturn($repository);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn(callable $callback): mixed => $callback());

        return $entityManager;
    }

    private function position(CustomerProject $project, string $name, int $position): ProjectPosition
    {
        return (new ProjectPosition())
            ->setCustomerProject($project)
            ->setName($name)
            ->setPosition($position);
    }
}

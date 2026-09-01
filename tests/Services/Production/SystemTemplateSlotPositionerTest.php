<?php

declare(strict_types=1);

namespace App\Tests\Services\Production;

use App\Entity\Production\SystemTemplate;
use App\Entity\Production\SystemTemplateSlot;
use App\Services\Production\SystemTemplateSlotPositioner;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class SystemTemplateSlotPositionerTest extends TestCase
{
    public function testMovingFourthSlotToFirstShiftsExistingSlotsBack(): void
    {
        $template = (new SystemTemplate())->setName('System');
        $first = $this->slot($template, 'Eins', 1);
        $second = $this->slot($template, 'Zwei', 2);
        $third = $this->slot($template, 'Drei', 3);
        $moved = $this->slot($template, 'Vier', 4)->setPosition(1);

        $positioner = new SystemTemplateSlotPositioner($this->entityManager([$first, $second, $third, $moved]));
        $positioner->save($moved, 4);

        self::assertSame(1, $moved->getPosition());
        self::assertSame(2, $first->getPosition());
        self::assertSame(3, $second->getPosition());
        self::assertSame(4, $third->getPosition());
    }

    public function testAddingSlotAtOccupiedPositionShiftsExistingSlotsBack(): void
    {
        $template = (new SystemTemplate())->setName('System');
        $first = $this->slot($template, 'Eins', 1);
        $second = $this->slot($template, 'Zwei', 2);
        $new = $this->slot($template, 'Neu', 1);

        $positioner = new SystemTemplateSlotPositioner($this->entityManager([$first, $second]));
        $positioner->save($new, null);

        self::assertSame(1, $new->getPosition());
        self::assertSame(2, $first->getPosition());
        self::assertSame(3, $second->getPosition());
    }

    /** @param list<SystemTemplateSlot> $persistedSlots */
    private function entityManager(array $persistedSlots): EntityManagerInterface
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn($persistedSlots);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(SystemTemplateSlot::class)->willReturn($repository);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn(callable $callback): mixed => $callback());

        return $entityManager;
    }

    private function slot(SystemTemplate $template, string $name, int $position): SystemTemplateSlot
    {
        return (new SystemTemplateSlot())
            ->setSystemTemplate($template)
            ->setName($name)
            ->setPosition($position);
    }
}

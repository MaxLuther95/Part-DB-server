<?php

declare(strict_types=1);

namespace App\Tests\Services\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\BuildMaterialUsage;
use App\Entity\Production\CustomerProject;
use App\Entity\Production\ProjectAccessory;
use App\Entity\Production\ProjectPosition;
use App\Entity\Production\SystemTemplate;
use App\Entity\Production\SystemTemplateSlot;
use App\Services\Production\SystemTemplateSlotPositioner;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
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

    public function testNextPositionIsAppendedAfterHighestSlot(): void
    {
        $template = (new SystemTemplate())->setName('System');
        $slots = [
            $this->slot($template, 'Eins', 1),
            $this->slot($template, 'Zwei', 4),
        ];

        $positioner = new SystemTemplateSlotPositioner($this->entityManager($slots));

        self::assertSame(5, $positioner->getNextPosition($template));
    }

    public function testRemovingSlotClosesPositionGap(): void
    {
        $template = (new SystemTemplate())->setName('System');
        $first = $this->slot($template, 'Eins', 1);
        $removed = $this->slot($template, 'Zwei', 2);
        $third = $this->slot($template, 'Drei', 3);
        $fourth = $this->slot($template, 'Vier', 4);
        $entityManager = $this->entityManager([$first, $removed, $third, $fourth]);
        $entityManager->expects(self::once())->method('remove')->with($removed);

        $preservedCount = (new SystemTemplateSlotPositioner($entityManager))->remove($removed);

        self::assertSame(0, $preservedCount);
        self::assertSame(1, $first->getPosition());
        self::assertSame(2, $third->getPosition());
        self::assertSame(3, $fourth->getPosition());
        self::assertNull($removed->getSystemTemplate());
    }

    public function testRemovingUsedSlotPreservesSelectionsAsIndependentOrderData(): void
    {
        $template = (new SystemTemplate())->setName('System');
        $removed = $this->slot($template, 'Auswahl', 1);
        $following = $this->slot($template, 'Danach', 2);

        $project = new CustomerProject();
        $rootPosition = (new ProjectPosition())
            ->setCustomerProject($project)
            ->setName('System')
            ->setPosition(4)
            ->setSystemTemplate($template);
        $selectedPosition = (new ProjectPosition())
            ->setCustomerProject($project)
            ->setName('Gewählte Baugruppe')
            ->setPosition(1)
            ->setSourceSlot($removed);
        $rootPosition->addChild($selectedPosition);
        $selectedPart = (new ProjectAccessory())
            ->setProjectPosition($rootPosition)
            ->setSourceSlot($removed)
            ->setQuantity(2);
        $builtParent = (new BuildInstance())->setSystemTemplate($template);
        $builtChild = (new BuildInstance())
            ->setParent($builtParent)
            ->setInstalledSlot($removed)
            ->setInstalledSlotIndex(0);
        $materialUsage = (new BuildMaterialUsage())->setSourceSlot($removed);

        $entityManager = $this->entityManager(
            [$removed, $following],
            [$selectedPosition],
            [$selectedPart],
            [$builtChild],
            [$materialUsage],
        );
        $entityManager->expects(self::once())->method('remove')->with($removed);

        $preservedCount = (new SystemTemplateSlotPositioner($entityManager))->remove($removed);

        self::assertSame(2, $preservedCount);
        self::assertNull($selectedPosition->getSourceSlot());
        self::assertSame($rootPosition, $selectedPosition->getParent());
        self::assertSame(1, $selectedPosition->getPosition());
        self::assertNotContains($selectedPosition, $project->getRootPositions());
        self::assertNull($selectedPart->getSourceSlot());
        self::assertSame($rootPosition, $selectedPart->getProjectPosition());
        self::assertSame($project, $selectedPart->getCustomerProject());
        self::assertSame($builtParent, $builtChild->getParent());
        self::assertNull($builtChild->getInstalledSlot());
        self::assertNull($builtChild->getInstalledSlotIndex());
        self::assertNull($materialUsage->getSourceSlot());
        self::assertSame(1, $following->getPosition());
    }

    /**
     * @param list<SystemTemplateSlot> $persistedSlots
     * @param list<ProjectPosition> $linkedPositions
     * @param list<ProjectAccessory> $linkedAccessories
     * @param list<BuildInstance> $installedInstances
     * @param list<BuildMaterialUsage> $materialUsages
     */
    private function entityManager(
        array $persistedSlots,
        array $linkedPositions = [],
        array $linkedAccessories = [],
        array $installedInstances = [],
        array $materialUsages = [],
    ): EntityManagerInterface&MockObject
    {
        $slotRepository = $this->createMock(EntityRepository::class);
        $slotRepository->method('findBy')->willReturn($persistedSlots);
        $positionRepository = $this->createMock(EntityRepository::class);
        $positionRepository->method('findBy')->willReturn($linkedPositions);
        $accessoryRepository = $this->createMock(EntityRepository::class);
        $accessoryRepository->method('findBy')->willReturn($linkedAccessories);
        $buildInstanceRepository = $this->createMock(EntityRepository::class);
        $buildInstanceRepository->method('findBy')->willReturn($installedInstances);
        $materialUsageRepository = $this->createMock(EntityRepository::class);
        $materialUsageRepository->method('findBy')->willReturn($materialUsages);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturnCallback(static fn(string $className): EntityRepository => match ($className) {
            SystemTemplateSlot::class => $slotRepository,
            ProjectPosition::class => $positionRepository,
            ProjectAccessory::class => $accessoryRepository,
            BuildInstance::class => $buildInstanceRepository,
            BuildMaterialUsage::class => $materialUsageRepository,
            default => throw new \LogicException(sprintf('Unexpected repository request for %s.', $className)),
        });
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn(callable $callback): mixed => $callback());

        return $entityManager;
    }

    private function slot(SystemTemplate $template, string $name, int $position): SystemTemplateSlot
    {
        $slot = (new SystemTemplateSlot())
            ->setName($name)
            ->setPosition($position);
        $template->addSlot($slot);

        return $slot;
    }
}

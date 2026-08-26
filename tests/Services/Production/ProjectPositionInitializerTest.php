<?php

declare(strict_types=1);

namespace App\Tests\Services\Production;

use App\Entity\Parts\Part;
use App\Entity\Production\CustomerProject;
use App\Entity\Production\ProjectAccessory;
use App\Entity\Production\ProjectPosition;
use App\Entity\Production\SystemTemplate;
use App\Entity\Production\SystemTemplateSlot;
use App\Entity\ProjectSystem\Project;
use App\Services\Production\ProjectPositionInitializer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ProjectPositionInitializerTest extends TestCase
{
    public function testUniqueRequiredContentsAreCreatedWithTheirMinimumQuantity(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $persisted = [];
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $batBox = (new Project())->setName('BatBox');
        $interface = (new Project())->setName('Interface');
        $channel = (new Project())->setName('Channel');
        $mainboard = (new SystemTemplate())->setName('Mainboard');
        $mainboard->addSlot((new SystemTemplateSlot())
            ->setName('Platz 1')
            ->setMinQuantity(0)
            ->setMaxQuantity(1)
            ->addAllowedProject($channel));

        $batBoxSlot = (new SystemTemplateSlot())
            ->setName('BatBox')
            ->setPosition(0)
            ->setMinQuantity(1)
            ->setMaxQuantity(1)
            ->addAllowedProject($batBox);
        $interfaceSlot = (new SystemTemplateSlot())
            ->setName('Interface')
            ->setPosition(1)
            ->setMinQuantity(1)
            ->setMaxQuantity(1)
            ->addAllowedProject($interface);
        $mainboardSlot = (new SystemTemplateSlot())
            ->setName('Mainboard')
            ->setPosition(2)
            ->setMinQuantity(2)
            ->setMaxQuantity(11)
            ->addAllowedSystemTemplate($mainboard);
        $system = (new SystemTemplate())
            ->setName('System')
            ->addSlot($batBoxSlot)
            ->addSlot($interfaceSlot)
            ->addSlot($mainboardSlot);
        $project = (new CustomerProject())->setProjectNumber('P-1');
        $position = (new ProjectPosition())
            ->setCustomerProject($project)
            ->setName('System')
            ->setSystemTemplate($system);

        $initializer = new ProjectPositionInitializer($entityManager);
        $initializer->initializeRequiredDefaults($position);

        self::assertCount(4, $position->getChildren());
        self::assertSame($batBox, $position->getAssignmentForSlot($batBoxSlot)?->getTemplateProject());
        self::assertSame($interface, $position->getAssignmentForSlot($interfaceSlot)?->getTemplateProject());
        self::assertSame(['Mainboard 1', 'Mainboard 2'], array_map(
            static fn(ProjectPosition $assignment): string => $assignment->getName(),
            $position->getAssignmentsForSlot($mainboardSlot),
        ));
        self::assertSame($mainboard, $position->getAssignmentsForSlot($mainboardSlot)[0]->getSystemTemplate());
        self::assertTrue($position->getAssignmentsForSlot($mainboardSlot)[0]->getChildren()->isEmpty());
        self::assertCount(4, $persisted);

        $initializer->initializeRequiredDefaults($position);
        self::assertCount(4, $position->getChildren(), 'Initialization must be idempotent.');
        self::assertCount(4, $persisted);
    }

    public function testUniqueRequiredInventoryPartIsCreatedAsPartAssignment(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $persisted = [];
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $part = (new Part())->setName('Standardkabel');
        $slot = (new SystemTemplateSlot())
            ->setName('Kabel')
            ->setMinQuantity(3)
            ->setMaxQuantity(3)
            ->setSerialTracking(true)
            ->addAllowedPart($part);
        $template = (new SystemTemplate())->setName('System')->addSlot($slot);
        $project = (new CustomerProject())->setProjectNumber('P-2');
        $position = (new ProjectPosition())
            ->setCustomerProject($project)
            ->setName('System')
            ->setSystemTemplate($template);

        (new ProjectPositionInitializer($entityManager))->initializeRequiredDefaults($position);

        $assignment = $position->getPartAssignmentForSlot($slot);
        self::assertInstanceOf(ProjectAccessory::class, $assignment);
        self::assertSame(3, $assignment->getQuantity());
        self::assertTrue($assignment->isSerialTracking());
        self::assertTrue($project->getAccessories()->contains($assignment));
        self::assertSame([$assignment], $persisted);
    }

    public function testOptionalAndAmbiguousRequiredSlotsRemainUserDecisions(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');

        $first = (new Project())->setName('First');
        $second = (new Project())->setName('Second');
        $template = (new SystemTemplate())
            ->setName('System')
            ->addSlot((new SystemTemplateSlot())
                ->setName('Optional')
                ->setMinQuantity(0)
                ->setMaxQuantity(1)
                ->addAllowedProject($first))
            ->addSlot((new SystemTemplateSlot())
                ->setName('Choice')
                ->setMinQuantity(1)
                ->setMaxQuantity(1)
                ->addAllowedProject($first)
                ->addAllowedProject($second));
        $position = (new ProjectPosition())
            ->setCustomerProject((new CustomerProject())->setProjectNumber('P-3'))
            ->setName('System')
            ->setSystemTemplate($template);

        (new ProjectPositionInitializer($entityManager))->initializeRequiredDefaults($position);

        self::assertTrue($position->getChildren()->isEmpty());
        self::assertTrue($position->getPartAssignments()->isEmpty());
    }

    public function testUniqueRequiredNestedSystemsAreInitializedRecursively(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('persist');

        $module = (new Project())->setName('Module');
        $nestedSlot = (new SystemTemplateSlot())
            ->setName('Module')
            ->setMinQuantity(1)
            ->setMaxQuantity(1)
            ->addAllowedProject($module);
        $nested = (new SystemTemplate())->setName('Nested')->addSlot($nestedSlot);
        $rootSlot = (new SystemTemplateSlot())
            ->setName('Nested')
            ->setMinQuantity(1)
            ->setMaxQuantity(1)
            ->addAllowedSystemTemplate($nested);
        $root = (new SystemTemplate())->setName('Root')->addSlot($rootSlot);
        $position = (new ProjectPosition())
            ->setCustomerProject((new CustomerProject())->setProjectNumber('P-4'))
            ->setName('Root')
            ->setSystemTemplate($root);

        (new ProjectPositionInitializer($entityManager))->initializeRequiredDefaults($position);

        $nestedPosition = $position->getAssignmentForSlot($rootSlot);
        self::assertInstanceOf(ProjectPosition::class, $nestedPosition);
        self::assertSame($module, $nestedPosition->getAssignmentForSlot($nestedSlot)?->getTemplateProject());
    }
}

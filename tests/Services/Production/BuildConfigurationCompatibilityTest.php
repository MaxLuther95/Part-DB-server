<?php

declare(strict_types=1);

namespace App\Tests\Services\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\BuildStatus;
use App\Entity\Production\CustomerProject;
use App\Entity\Production\ProjectPosition;
use App\Entity\Production\SystemTemplate;
use App\Entity\Production\SystemTemplateSlot;
use App\Entity\ProjectSystem\Project;
use App\Services\Production\BuildConfigurationCompatibility;
use PHPUnit\Framework\TestCase;

final class BuildConfigurationCompatibilityTest extends TestCase
{
    public function testParentAndConfiguredChildAreAssignedSeparately(): void
    {
        $electronics = new Project();
        $mainboard = (new SystemTemplate())->setName('Mainboard');
        $slot = (new SystemTemplateSlot())
            ->setName('Platz 1')
            ->setMinQuantity(1)
            ->setMaxQuantity(1)
            ->addAllowedProject($electronics);
        $mainboard->addSlot($slot);

        $customerProject = (new CustomerProject())->setProjectNumber('P-TEST');
        $plannedRoot = (new ProjectPosition())
            ->setCustomerProject($customerProject)
            ->setName('Mainboard')
            ->setSystemTemplate($mainboard);
        $plannedChild = (new ProjectPosition())
            ->setCustomerProject($customerProject)
            ->setName('Platz 1')
            ->setSourceSlot($slot)
            ->setTemplateProject($electronics);
        $plannedRoot->addChild($plannedChild);

        $builtRoot = (new BuildInstance())
            ->setSystemTemplate($mainboard)
            ->setSerialNumber('MB-1')
            ->setStatus(BuildStatus::Completed);
        $builtChild = (new BuildInstance())
            ->setTemplateProject($electronics)
            ->setSerialNumber('EL-1')
            ->setStatus(BuildStatus::Completed);

        $compatibility = new BuildConfigurationCompatibility();
        self::assertTrue($compatibility->isCompatible($builtRoot, $plannedRoot));
        self::assertTrue($compatibility->assign($builtRoot, $plannedRoot));
        self::assertSame($plannedRoot, $builtRoot->getProjectPosition());
        self::assertNull($builtChild->getProjectPosition());

        self::assertTrue($compatibility->isCompatible($builtChild, $plannedChild));
        self::assertTrue($compatibility->assign($builtChild, $plannedChild));
        self::assertSame($plannedChild, $builtChild->getProjectPosition());
        self::assertSame($builtRoot, $builtChild->getParent());
        self::assertSame($slot, $builtChild->getInstalledSlot());
        self::assertSame(0, $builtChild->getInstalledSlotIndex());
        self::assertSame(BuildStatus::Installed, $builtChild->getStatus());

        self::assertFalse($compatibility->unassign($builtRoot));
        self::assertSame($plannedRoot, $builtRoot->getProjectPosition());
        self::assertTrue($compatibility->unassign($builtChild));
        self::assertNull($builtChild->getProjectPosition());
        self::assertNull($builtChild->getParent());
        self::assertSame(BuildStatus::Completed, $builtChild->getStatus());
        self::assertTrue($compatibility->unassign($builtRoot));
        self::assertNull($builtRoot->getProjectPosition());
    }

    public function testChildCanBeAssignedBeforeItsConfiguredParentAndIsAttachedLater(): void
    {
        $electronics = new Project();
        $mainboard = (new SystemTemplate())->setName('Mainboard');
        $plannedSlot = (new SystemTemplateSlot())->setName('Platz 1')->setMinQuantity(1)->addAllowedProject($electronics);
        $mainboard->addSlot($plannedSlot);

        $plannedRoot = (new ProjectPosition())->setSystemTemplate($mainboard);
        $plannedChild = (new ProjectPosition())->setSourceSlot($plannedSlot)->setTemplateProject($electronics);
        $plannedRoot->addChild($plannedChild);
        $builtChild = (new BuildInstance())
            ->setTemplateProject($electronics)
            ->setSerialNumber('EL-2')
            ->setStatus(BuildStatus::Completed);

        $compatibility = new BuildConfigurationCompatibility();
        self::assertTrue($compatibility->assign($builtChild, $plannedChild));
        self::assertNull($builtChild->getParent());

        $builtRoot = (new BuildInstance())
            ->setSystemTemplate($mainboard)
            ->setSerialNumber('MB-2')
            ->setStatus(BuildStatus::InProgress);
        self::assertTrue($compatibility->assign($builtRoot, $plannedRoot));
        self::assertSame($builtRoot, $builtChild->getParent());
        self::assertSame($plannedSlot, $builtChild->getInstalledSlot());
        self::assertSame(BuildStatus::Installed, $builtChild->getStatus());
    }

    public function testInProgressSystemInstanceCanFillMatchingSystemPosition(): void
    {
        $template = (new SystemTemplate())->setName('Mainboard');
        $position = (new ProjectPosition())->setSystemTemplate($template);
        $instance = (new BuildInstance())
            ->setSystemTemplate($template)
            ->setSerialNumber('MB-3')
            ->setStatus(BuildStatus::InProgress);

        $compatibility = new BuildConfigurationCompatibility();
        self::assertTrue($compatibility->isCompatible($instance, $position));
        self::assertTrue($compatibility->assign($instance, $position));
        self::assertSame($position, $instance->getProjectPosition());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Entity\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\BuildStatus;
use App\Entity\Production\Customer;
use App\Entity\Production\CustomerProject;
use App\Entity\Production\CustomerProjectStatus;
use App\Entity\Production\ProjectPosition;
use App\Entity\Production\ProjectMaterialAllocation;
use App\Entity\Production\SystemTemplate;
use App\Entity\Production\SystemTemplateSlot;
use App\Entity\Parts\Part;
use App\Entity\ProjectSystem\Project;
use PHPUnit\Framework\TestCase;

final class ProductionEntitiesTest extends TestCase
{
    public function testCustomerAndProjectDefaults(): void
    {
        $customer = (new Customer())
            ->setCustomerNumber(' K-100 ')
            ->setName(' ACME GmbH ');

        $project = (new CustomerProject())
            ->setProjectNumber(' P-2026-0042 ')
            ->setName(' Messsystem ')
            ->setCustomer($customer);

        self::assertSame('K-100', $customer->getCustomerNumber());
        self::assertSame('ACME GmbH', $customer->getName());
        self::assertTrue($customer->isActive());
        self::assertSame(CustomerProjectStatus::Planning, $project->getStatus());
        self::assertSame($customer, $project->getCustomer());
    }

    public function testCompletedBuildGetsCompletionTimestamp(): void
    {
        $build = (new BuildInstance())
            ->setSerialNumber(' E1-2600127 ')
            ->setTemplateProject(new Project())
            ->setLocation(' Regal 2 ');

        self::assertSame(BuildStatus::Planned, $build->getStatus());
        self::assertNull($build->getCompletedAt());

        $build->setStatus(BuildStatus::Completed);

        self::assertSame('E1-2600127', $build->getSerialNumber());
        self::assertSame('Regal 2', $build->getLocation());
        self::assertNotNull($build->getCompletedAt());
    }

    public function testProjectPositionAssignsBuildToProjectAndTemplate(): void
    {
        $project = (new CustomerProject())->setProjectNumber('P-2026-0042');
        $template = new Project();
        $position = (new ProjectPosition())
            ->setCustomerProject($project)
            ->setTemplateProject($template)
            ->setName('Elektronik Slot 1');

        $build = (new BuildInstance())->setProjectPosition($position);

        self::assertSame($position, $build->getProjectPosition());
        self::assertSame($project, $build->getCustomerProject());
        self::assertSame($template, $build->getTemplateProject());

        $build->setProjectPosition(null);

        self::assertNull($build->getProjectPosition());
        self::assertNull($build->getCustomerProject());
        self::assertSame($template, $build->getTemplateProject());
    }

    public function testBuildCannotBeAssignedDirectlyToCustomerProject(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new BuildInstance())->setCustomerProject(new CustomerProject());
    }

    public function testSystemTemplateDefinesProjectAndInventoryChoices(): void
    {
        $baseProject = new Project();
        $builtChoice = new Project();
        $purchasedChoice = new Part();
        $template = (new SystemTemplate())
            ->setName('System A')
            ->setBaseProject($baseProject);
        $nestedTemplate = (new SystemTemplate())->setName('Nested system');
        $slot = (new SystemTemplateSlot())
            ->setSystemTemplate($template)
            ->setName('Elektronikkanäle')
            ->setMinQuantity(1)
            ->setMaxQuantity(108)
            ->setSerialTracking(true)
            ->addAllowedSystemTemplate($nestedTemplate)
            ->addAllowedProject($builtChoice)
            ->addAllowedPart($purchasedChoice);

        $position = (new ProjectPosition())->setSystemTemplate($template);

        self::assertSame($template, $position->getSystemTemplate());
        self::assertNull($position->getTemplateProject());
        self::assertSame($baseProject, $position->getBuildProject());
        self::assertTrue($slot->isRequired());
        self::assertTrue($slot->isSerialTracking());
        self::assertTrue($slot->getAllowedSystemTemplates()->contains($nestedTemplate));
        self::assertTrue($slot->allows($builtChoice));
        self::assertTrue($slot->getAllowedParts()->contains($purchasedChoice));
    }

    public function testProjectMaterialAllocationKeepsSerialNumber(): void
    {
        $allocation = (new ProjectMaterialAllocation())
            ->setPart(new Part())
            ->setQuantity(1)
            ->setSerialNumber(' E18-4711 ');

        self::assertSame(1.0, $allocation->getQuantity());
        self::assertSame('E18-4711', $allocation->getSerialNumber());
    }
}

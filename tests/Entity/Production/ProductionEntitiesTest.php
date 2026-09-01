<?php

declare(strict_types=1);

namespace App\Tests\Entity\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\BuildStatus;
use App\Entity\Production\Customer;
use App\Entity\Production\CustomerProject;
use App\Entity\Production\CustomerProjectStatus;
use App\Entity\Production\OrderImportLine;
use App\Entity\Production\OrderImportMapping;
use App\Entity\Production\OrderPositionUnit;
use App\Entity\Production\ProjectPosition;
use App\Entity\Production\ProductionProject;
use App\Entity\Production\ProductionProjectStatus;
use App\Entity\Production\ProjectMaterialAllocation;
use App\Entity\Production\ProjectMaterialReservation;
use App\Entity\Production\SystemTemplate;
use App\Entity\Production\SystemTemplateSlot;
use App\Entity\Parts\Part;
use App\Entity\ProjectSystem\Project;
use App\Entity\UserSystem\User;
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
            ->setNotes(' Projekt intern abstimmen. ')
            ->setCustomer($customer);

        $productionProject = (new ProductionProject())
            ->setProjectNumber('PR-100')
            ->setName('Messsystem-Familie');
        $project->setProductionProject($productionProject);

        self::assertSame('K-100', $customer->getCustomerNumber());
        self::assertSame('ACME GmbH', $customer->getName());
        self::assertTrue($customer->isActive());
        self::assertSame(CustomerProjectStatus::Planning, $project->getStatus());
        self::assertSame('Projekt intern abstimmen.', $project->getNotes());
        self::assertSame($customer, $project->getCustomer());
        self::assertSame($productionProject, $project->getProductionProject());
        self::assertSame(ProductionProjectStatus::Planning, $productionProject->getStatus());
    }

    public function testProjectCanBeAssignedToMultipleUsers(): void
    {
        $firstUser = new User();
        $secondUser = new User();
        $project = (new CustomerProject())
            ->addAssignedUser($firstUser)
            ->addAssignedUser($secondUser);

        self::assertTrue($project->isAssignedTo($firstUser));
        self::assertSame([$firstUser, $secondUser], $project->getAssignedUsers()->toArray());

        $project->removeAssignedUser($firstUser);

        self::assertFalse($project->isAssignedTo($firstUser));
        self::assertTrue($project->isAssignedTo($secondUser));
    }

    public function testCompletedBuildGetsCompletionTimestamp(): void
    {
        $build = (new BuildInstance())
            ->setSerialNumber(' E1-2600127 ')
            ->setTemplateProject(new Project())
            ->setLocation(' Regal 2 ')
            ->setNotes(' Fertigungsprüfung ohne Befund. ');

        self::assertSame(BuildStatus::Planned, $build->getStatus());
        self::assertNull($build->getCompletedAt());

        $build->setStatus(BuildStatus::Completed);

        self::assertSame('E1-2600127', $build->getSerialNumber());
        self::assertSame('Regal 2', $build->getLocation());
        self::assertSame('Fertigungsprüfung ohne Befund.', $build->getNotes());
        self::assertNotNull($build->getCompletedAt());
    }

    public function testBuildWithoutSerialUsesInternalIdentifierAndKeepsReason(): void
    {
        $build = (new BuildInstance())
            ->setTemplateProject(new Project())
            ->setSerialNumber(' ')
            ->setNotes('Prototyp ohne Typenschild');

        self::assertNull($build->getSerialNumber());
        self::assertSame('Ohne Seriennummer', $build->getDisplayIdentifier());
        self::assertSame('Prototyp ohne Typenschild', $build->getNotes());
    }

    public function testProjectCompletionRequiresSerializedDeviceOnEveryPosition(): void
    {
        $project = (new CustomerProject())->setProjectNumber('P-2026-0999');
        $first = (new ProjectPosition())->setCustomerProject($project)->setName('Position 1')->setTemplateProject(new Project());
        $second = (new ProjectPosition())->setCustomerProject($project)->setName('Position 2')->setTemplateProject(new Project());

        (new BuildInstance())->setProjectPosition($first)->setSerialNumber('SN-1');
        (new BuildInstance())->setProjectPosition($second)->setNotes('Seriennummer wird später vergeben');

        self::assertFalse($project->isReadyForCompletion());
        self::assertSame([$second], $project->getPositionsMissingSerialNumber());

        $second->getBuildInstances()->first()->setSerialNumber('SN-2');
        self::assertTrue($project->isReadyForCompletion());
    }

    public function testProjectPositionAssignsBuildToProjectAndTemplate(): void
    {
        $project = (new CustomerProject())->setProjectNumber('P-2026-0042');
        $template = new Project();
        $position = (new ProjectPosition())
            ->setCustomerProject($project)
            ->setTemplateProject($template)
            ->setName('Elektronik Slot 1')
            ->setNotes(' Nur für dieses Projekt. ');

        $build = (new BuildInstance())->setProjectPosition($position);

        self::assertSame($position, $build->getProjectPosition());
        self::assertSame($project, $build->getCustomerProject());
        self::assertSame($template, $build->getTemplateProject());
        self::assertSame('Nur für dieses Projekt.', $position->getNotes());

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
        $secondBaseProject = new Project();
        $builtChoice = new Project();
        $purchasedChoice = new Part();
        $template = (new SystemTemplate())
            ->setName('System A')
            ->addBaseProject($baseProject)
            ->addBaseProject($secondBaseProject);
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
        self::assertSame([$baseProject, $secondBaseProject], $position->getBuildProjects());
        self::assertTrue($slot->isRequired());
        self::assertTrue($slot->isSerialTracking());
        self::assertTrue($slot->getAllowedSystemTemplates()->contains($nestedTemplate));
        self::assertSame([$template], $nestedTemplate->getParentTemplates());
        self::assertTrue($slot->allows($builtChoice));
        self::assertTrue($slot->getAllowedParts()->contains($purchasedChoice));
    }

    public function testOrderPositionUnitsAreFixedByTheirMappedTarget(): void
    {
        $template = (new SystemTemplate())->setOrderUnit(OrderPositionUnit::Set);
        $systemMapping = (new OrderImportMapping())->setSystemTemplate($template);
        $partMapping = (new OrderImportMapping())->setPart(new Part());
        $line = (new OrderImportLine())->setUnit('pieces');

        self::assertSame(OrderPositionUnit::Set, $systemMapping->getOrderUnit());
        self::assertSame(OrderPositionUnit::Piece, $partMapping->getOrderUnit());
        self::assertSame('pcs.', $line->getUnit());

        $this->expectException(\InvalidArgumentException::class);
        $line->setUnit('free text');
    }

    public function testProjectMaterialAllocationKeepsSerialNumber(): void
    {
        $allocation = (new ProjectMaterialAllocation())
            ->setPart(new Part())
            ->setQuantity(1)
            ->setSerialNumber(' E18-4711 ');

        self::assertSame(1, $allocation->getQuantity());
        self::assertSame($allocation->getPart()?->getName(), $allocation->getPartName());
        self::assertSame('E18-4711', $allocation->getSerialNumber());
    }

    public function testProjectMaterialReservationUsesWholeQuantitiesWithoutChangingStock(): void
    {
        $project = (new CustomerProject())->setProjectNumber('P-RESERVE');
        $part = new Part();
        $reservation = (new ProjectMaterialReservation())
            ->setCustomerProject($project)
            ->setPart($part)
            ->setQuantity(4);

        self::assertSame($project, $reservation->getCustomerProject());
        self::assertSame($part, $reservation->getPart());
        self::assertSame(4, $reservation->getQuantity());
    }

    public function testSystemTemplateCannotContainItselfIndirectly(): void
    {
        $first = (new SystemTemplate())->setName('First');
        $second = (new SystemTemplate())->setName('Second');
        $third = (new SystemTemplate())->setName('Third');

        $first->addSlot((new SystemTemplateSlot())
            ->setName('Second')
            ->addAllowedSystemTemplate($second));
        $second->addSlot((new SystemTemplateSlot())
            ->setName('Third')
            ->addAllowedSystemTemplate($third));
        $cyclicSlot = (new SystemTemplateSlot())
            ->setName('First')
            ->addAllowedSystemTemplate($first);
        $third->addSlot($cyclicSlot);

        self::assertTrue($cyclicSlot->introducesTemplateCycle());

        $nonCyclicSlot = (new SystemTemplateSlot())
            ->setSystemTemplate($third)
            ->setName('Unrelated')
            ->addAllowedSystemTemplate((new SystemTemplate())->setName('Unrelated'));
        self::assertFalse($nonCyclicSlot->introducesTemplateCycle());
    }

    public function testRepeatedNestedSystemsRemainIndividualPositions(): void
    {
        $mainboards = (new SystemTemplateSlot())->setName('Mainboards')->setPosition(0);
        $interface = (new SystemTemplateSlot())->setName('Interface')->setPosition(1);
        $template = (new SystemTemplate())
            ->setName('Demo system')
            ->addSlot($mainboards)
            ->addSlot($interface);
        $position = (new ProjectPosition())->setName('Demo system')->setSystemTemplate($template);

        $mainboardOne = (new ProjectPosition())->setName('Mainboard 1')->setSourceSlot($mainboards);
        $mainboardTwo = (new ProjectPosition())->setName('Mainboard 2')->setSourceSlot($mainboards);
        $interfacePosition = (new ProjectPosition())->setName('Interface')->setSourceSlot($interface);
        $position->addChild($mainboardOne)->addChild($mainboardTwo)->addChild($interfacePosition);

        self::assertSame([$mainboardOne, $mainboardTwo], $position->getAssignmentsForSlot($mainboards));
        self::assertSame(0, $position->getDisplayOffsetForSlot($mainboards));
        self::assertSame(2, $position->getDisplayOffsetForSlot($interface));
        self::assertSame($position, $mainboardTwo->getParent());
    }
}

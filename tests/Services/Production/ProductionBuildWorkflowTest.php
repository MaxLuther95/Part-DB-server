<?php

declare(strict_types=1);

namespace App\Tests\Services\Production;

use App\Entity\Parts\Part;
use App\Entity\Parts\PartLot;
use App\Entity\Production\ProjectPosition;
use App\Entity\Production\SystemTemplate;
use App\Repository\Production\ProjectMaterialReservationRepository;
use App\Services\Parts\PartLotWithdrawAddHelper;
use App\Services\Production\BuildConfigurationCompatibility;
use App\Services\Production\ProductionBuildWorkflow;
use App\Services\Production\ProductionHistoryRecorder;
use App\Services\Production\ProductionReservationManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ProductionBuildWorkflowTest extends TestCase
{
    public function testFreeSystemBuildCreatesOnlyTheTopLevelInstance(): void
    {
        $workflow = $this->createWorkflow();

        $template = (new SystemTemplate())->setName('Top-level system');
        $draft = $workflow->createDraft($template);

        self::assertSame('n0', $draft['root']);
        self::assertCount(1, $draft['nodes']);
        self::assertSame('system', $draft['nodes']['n0']['type']);
        self::assertTrue($draft['nodes']['n0']['configured']);
        self::assertNull($draft['nodes']['n0']['parent']);
    }

    public function testOrderPositionBuildDoesNotCreateConfiguredChildInstances(): void
    {
        $workflow = $this->createWorkflow();

        $root = (new ProjectPosition())
            ->setName('Configured system')
            ->setSystemTemplate((new SystemTemplate())->setName('System'));
        $root->addChild((new ProjectPosition())
            ->setName('Already configured child')
            ->setSystemTemplate((new SystemTemplate())->setName('Child system')));

        $draft = $workflow->createDraft($root->getSystemTemplate(), $root);

        self::assertCount(1, $draft['nodes']);
        self::assertSame('Configured system', $draft['nodes']['n0']['name']);
        self::assertNull($draft['nodes']['n0']['parent']);
    }

    public function testAutomaticLotAllocationRebuildsEveryRequiredPartFromCurrentPlan(): void
    {
        $workflow = $this->createWorkflow();
        $screw = $this->part(439, 'Flachkopfschraube');
        $connector = $this->part(510, 'Steckverbinder');
        $firstLot = $this->lot(598);
        $secondLot = $this->lot(599);
        $connectorLot = $this->lot(600);
        $plan = [
            'complete' => true,
            'items' => [
                ['part' => $screw, 'remaining' => 3, 'lots' => [
                    ['lot' => $firstLot, 'available' => 2],
                    ['lot' => $secondLot, 'available' => 8],
                ]],
                ['part' => $connector, 'remaining' => 1, 'lots' => [
                    ['lot' => $connectorLot, 'available' => 5],
                ]],
            ],
        ];

        $allocation = $workflow->allocateAvailableLots($plan);

        self::assertSame([
            '439' => ['598' => 2, '599' => 1],
            '510' => ['600' => 1],
        ], $allocation);
        self::assertSame([], $workflow->validateMaterialSelection([
            'lots' => $allocation,
            'materials_taken' => ['439' => true, '510' => true],
        ], $plan));
    }

    public function testMaterialSelectionValidationRejectsStaleOrUnconfirmedAllocation(): void
    {
        $workflow = $this->createWorkflow();
        $part = $this->part(440, 'Linsenschraube');
        $plan = [
            'complete' => true,
            'items' => [[
                'part' => $part,
                'remaining' => 3,
                'lots' => [['lot' => $this->lot(599), 'available' => 10]],
            ]],
        ];

        self::assertSame(
            ['Linsenschraube: Bitte die Materialentnahme bestätigen.'],
            $workflow->validateMaterialSelection(['lots' => ['440' => ['599' => 3]], 'materials_taken' => []], $plan)
        );
        self::assertSame(
            ['Linsenschraube: Die automatische Lagerplatzzuordnung deckt den Bedarf nicht vollständig.'],
            $workflow->validateMaterialSelection(['lots' => ['440' => ['599' => 0]], 'materials_taken' => ['440' => true]], $plan)
        );
    }

    private function createWorkflow(): ProductionBuildWorkflow
    {
        return new ProductionBuildWorkflow(
            $this->createMock(EntityManagerInterface::class),
            $this->inert(PartLotWithdrawAddHelper::class),
            $this->inert(ProductionHistoryRecorder::class),
            $this->inert(BuildConfigurationCompatibility::class),
            $this->inert(ProjectMaterialReservationRepository::class),
            $this->inert(ProductionReservationManager::class),
            $this->inert(\App\Services\Production\SerialNumberManager::class),
        );
    }

    private function part(int $id, string $name): Part
    {
        $part = $this->createMock(Part::class);
        $part->method('getID')->willReturn($id);
        $part->method('getName')->willReturn($name);

        return $part;
    }

    private function lot(int $id): PartLot
    {
        $lot = $this->createMock(PartLot::class);
        $lot->method('getID')->willReturn($id);

        return $lot;
    }

    /** @template T of object @param class-string<T> $class @return T */
    private function inert(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}

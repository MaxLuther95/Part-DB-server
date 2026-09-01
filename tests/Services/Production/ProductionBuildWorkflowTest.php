<?php

declare(strict_types=1);

namespace App\Tests\Services\Production;

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

    private function createWorkflow(): ProductionBuildWorkflow
    {
        return new ProductionBuildWorkflow(
            $this->createMock(EntityManagerInterface::class),
            $this->inert(PartLotWithdrawAddHelper::class),
            $this->inert(ProductionHistoryRecorder::class),
            $this->inert(BuildConfigurationCompatibility::class),
            $this->inert(ProjectMaterialReservationRepository::class),
            $this->inert(ProductionReservationManager::class),
        );
    }

    /** @template T of object @param class-string<T> $class @return T */
    private function inert(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}

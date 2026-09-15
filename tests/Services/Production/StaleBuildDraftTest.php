<?php

declare(strict_types=1);

namespace App\Tests\Services\Production;

use App\Entity\Parts\PartLot;
use App\Entity\Production\{BuildInstance, BuildMaterialUsage, CustomerProjectStatus, SerialNumberRange, SystemTemplate};
use App\Entity\UserSystem\User;
use App\Services\Production\{ProductionBuildWorkflow, StaleBuildDraftException};
use App\Tests\Fixtures\StaleBuildScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StaleBuildDraftTest extends KernelTestCase
{
    public static function changes(): iterable
    {
        foreach (['cancelled', 'completed', 'delivered', 'planning', 'commissioned', 'site', 'accessory', 'quantity', 'legacy', 'missing_snapshot', 'deleted_position', 'deleted_type', 'occupied_position'] as $change) {
            yield $change => [$change];
        }
    }

    #[DataProvider('changes')]
    public function testChangedDraftCannotClaimSerialOrWithdrawMaterial(string $change): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $workflow = self::getContainer()->get(ProductionBuildWorkflow::class);
        $s = StaleBuildScenario::create($em, $workflow);
        $draft = $s['draft'];
        $lotId = $s['lot']->getId();
        $rangeId = $s['range']->getId();
        if (in_array($change, ['cancelled', 'completed', 'delivered', 'planning', 'commissioned'], true)) {
            $s['order']->setStatus(CustomerProjectStatus::from($change));
        } elseif ('site' === $change) {
            $s['order']->setProductionSite(null);
        } elseif ('accessory' === $change) {
            $s['accessory']->setQuantity(2);
        } elseif ('quantity' === $change) {
            $s['position']->setQuantity(2);
        } elseif ('bom' === $change) {
            $s['bom']->setQuantity(3);
        } elseif ('legacy' === $change) {
            $draft['version'] = 3;
        } elseif ('deleted_position' === $change) {
            $em->remove($s['position']);
        } elseif ('deleted_type' === $change) {
            $em->remove($s['original']);
        } elseif ('missing_snapshot' === $change) {
            unset($draft['nodes']['n0']['content_signature']);
        } elseif ('occupied_position' === $change) {
            $em->persist((new BuildInstance())->setProjectPosition($s['position']));
        }
        $em->flush();
        $em->clear(); // A later HTTP request reloads current DB state, retaining the old session draft.
        $beforeBuilds = $em->getRepository(BuildInstance::class)->count([]);
        $beforeUsages = $em->getRepository(BuildMaterialUsage::class)->count([]);
        try {
            $workflow->finalize($draft, $em->getRepository(User::class)->findOneBy(['name' => 'admin']));
            self::fail('A stale build was committed: '.$change);
        } catch (StaleBuildDraftException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
        self::assertTrue($em->isOpen());
        $em->clear();
        self::assertSame($beforeBuilds, $em->getRepository(BuildInstance::class)->count([]));
        self::assertSame($beforeUsages, $em->getRepository(BuildMaterialUsage::class)->count([]));
        self::assertSame(10.0, $em->find(PartLot::class, $lotId)->getAmount());
        self::assertSame(1, $em->find(SerialNumberRange::class, $rangeId)->getNextNumber());
    }

    public function testUnchangedDraftAndDescriptiveEditsStillBuildNormally(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $workflow = self::getContainer()->get(ProductionBuildWorkflow::class);
        $s = StaleBuildScenario::create($em, $workflow);
        $s['order']->setDescription('A descriptive change does not alter the reviewed build.');
        $s['position']->setNotes('Additional planning note');
        $s['original']->setName('Renamed template');
        $s['bom']->setQuantity(200); // Existing orders still build from their captured BOM.
        $em->flush();
        $lotId = $s['lot']->getId();
        $rangeId = $s['range']->getId();
        $em->clear();
        $instance = $workflow->finalize($s['draft'], $em->getRepository(User::class)->findOneBy(['name' => 'admin']));
        self::assertSame('STL-0001', $instance->getSerialNumber());
        $em->clear();
        self::assertSame(7.0, $em->find(PartLot::class, $lotId)->getAmount());
        self::assertSame(2, $em->find(SerialNumberRange::class, $rangeId)->getNextNumber());
    }

    public function testFreshlyReviewedReplacementUsesItsOwnMaterialPlan(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $workflow = self::getContainer()->get(ProductionBuildWorkflow::class);
        $s = StaleBuildScenario::create($em, $workflow);
        $old = $s['position'];
        $replacement = (new \App\Entity\Production\ProjectPosition())->setCustomerProject($s['order'])->setTemplateProject($s['replacement'])->setName('Manually replaced');
        $s['accessory']->setProjectPosition($replacement);
        $s['order']->getPositions()->removeElement($old);
        $em->remove($old);
        $em->persist($replacement);
        $s['position'] = $replacement;
        $em->flush();
        $draft = $workflow->createDraft($s['replacement'], $s['position']);
        $draft['details'] = $s['draft']['details']; // Explicitly confirmed again in the new wizard.
        $plan = $workflow->createMaterialPlan($draft, $s['site']);
        $draft['lots'] = $workflow->allocateAvailableLots($plan);
        $draft['materials_taken'][(string) $s['part']->getId()] = true;
        $instance = $workflow->finalize($draft, $em->getRepository(User::class)->findOneBy(['name' => 'admin']));
        self::assertSame($s['replacement'], $instance->getTemplateProject());
        self::assertSame(9.0, $s['lot']->getAmount()); // Only the current position accessory, not the old BOM.
    }

    public function testExistingPositionRejectsChangingItsManufacturingType(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $s = StaleBuildScenario::create($em, self::getContainer()->get(ProductionBuildWorkflow::class));
        $this->expectException(\DomainException::class);
        $s['position']->setTemplateProject($s['replacement']);
    }

    public function testFreeBuildAlsoRejectsAnUpdatedBillOfMaterials(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $workflow = self::getContainer()->get(ProductionBuildWorkflow::class);
        $s = StaleBuildScenario::create($em, $workflow);
        $draft = $workflow->createDraft($s['original']);
        $s['bom']->setQuantity(3);
        $em->flush();
        $em->clear();
        $this->expectException(StaleBuildDraftException::class);
        $workflow->assertCurrentDraft($draft);
    }
}

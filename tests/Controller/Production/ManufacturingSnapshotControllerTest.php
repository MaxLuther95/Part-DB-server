<?php

declare(strict_types=1);

namespace App\Tests\Controller\Production;

use App\Entity\Parts\Part;
use App\Entity\Production\{Customer, CustomerProject, CustomerProjectStatus, ManufacturingSnapshot, ProductionProject, ProjectPosition, SystemTemplate, SystemTemplateSlot};
use App\Entity\ProjectSystem\{Project, ProjectBOMEntry};
use App\Entity\UserSystem\User;
use App\Services\Production\{ManufacturingSnapshotMigrationPlan, ProductionBuildWorkflow, ProductionMaterialPlanner, ProjectPositionInitializer, SystemTemplateSlotPositioner};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ManufacturingSnapshotControllerTest extends WebTestCase
{
    private function scenario(): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->setNeedPwChange(false);
        $part = $em->getRepository(Part::class)->findOneBy([]);
        $base = (new Project())->setName('Original base project');
        $baseBom = (new ProjectBOMEntry())->setPart($part)->setQuantity(2);
        $base->addBomEntry($baseBom);
        $prepared = (new Project())->setName('Prepared assembly');
        $preparedBom = (new ProjectBOMEntry())->setPart($part)->setQuantity(3);
        $prepared->addBomEntry($preparedBom);
        $optional = (new Project())->setName('Optional assembly');
        $optionalBom = (new ProjectBOMEntry())->setPart($part)->setQuantity(7);
        $optional->addBomEntry($optionalBom);
        $nested = (new SystemTemplate())->setName('Nested original')->setBaseProject($prepared);
        $system = (new SystemTemplate())->setName('System original')->setBaseProject($base);
        $slot = (new SystemTemplateSlot())->setName('Nested slot original')->setMinQuantity(1)->setMaxQuantity(1)->setPosition(0);
        $slot->addAllowedSystemTemplate($nested);
        $system->addSlot($slot);
        $option = (new SystemTemplateSlot())->setName('Optional slot original')->setMinQuantity(0)->setMaxQuantity(1)->setPosition(1);
        $option->addAllowedProject($optional);
        $system->addSlot($option);
        $customer = (new Customer())->setName('Snapshot customer')->setCustomerNumber('SNAP-C');
        $project = (new ProductionProject())->setName('Snapshot project')->setProjectNumber('SNAP-P');
        foreach ([$base,$baseBom,$prepared,$preparedBom,$optional,$optionalBom,$nested,$system,$customer,$project] as $entity) { $em->persist($entity); }
        $em->flush();
        $order = (new CustomerProject())->setOrderNumber('SNAP-O')->setCustomer($customer)->setProductionProject($project)->setStatus(CustomerProjectStatus::InProduction);
        $position = (new ProjectPosition())->setCustomerProject($order)->setSystemTemplate($system)->setName('Original order position');
        $em->persist($order);
        $em->persist($position);
        self::getContainer()->get(ProjectPositionInitializer::class)->initializeRequiredDefaults($position);
        $em->flush();
        return compact('admin','part','system','nested','slot','option','base','baseBom','preparedBom','optional','optionalBom','order','position');
    }

    public function testCompleteDefinitionSurvivesChangesAndLateOptionSelection(): void
    {
        $client = self::createClient();
        $s = $this->scenario();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $position = $s['position'];
        $planner = self::getContainer()->get(ProductionMaterialPlanner::class);
        $workflow = self::getContainer()->get(ProductionBuildWorkflow::class);
        $draft = $workflow->createDraft($s['system'], $position);
        self::assertSame(5, $planner->getRequirements($s['order'])[$s['part']->getId()]['required']);
        self::assertCount(1, $position->getChildren());
        $snapshotId = $position->getManufacturingSnapshot()->getId();
        $s['baseBom']->setQuantity(20);
        $s['preparedBom']->setQuantity(30);
        $s['optionalBom']->setQuantity(70);
        $s['system']->setName('System changed')->setBaseProject($s['optional']);
        $s['nested']->setName('Nested changed');
        $s['slot']->setName('Slot changed')->setMinQuantity(0);
        $s['option']->setMinQuantity(1)->setMaxQuantity(4);
        $em->flush();
        $workflow->assertCurrentDraft($draft);
        self::assertSame(5, $planner->getRequirements($s['order'])[$s['part']->getId()]['required']);
        self::assertSame(2, $workflow->createMaterialPlan($draft, null)['items'][0]['required']);
        self::assertSame('System original', $position->getContentName());
        self::assertSame('Nested original', $position->getChildren()->first()->getContentName());
        $client->loginUser($s['admin']);
        $crawler = $client->request('GET', '/en/production/project-positions/'.$position->getId().'/configure');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('form[name="form"]', 'Nested slot original');
        self::assertSelectorTextNotContains('form[name="form"]', 'Slot changed');
        $client->submit($crawler->filter('form[name="form"]')->form([
            'form[content_'.$s['option']->getId().']' => 'project_'.$s['optional']->getId(),
        ]));
        self::assertResponseRedirects();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $order = $em->find(CustomerProject::class, $s['order']->getId());
        self::assertSame(12, self::getContainer()->get(ProductionMaterialPlanner::class)->getRequirements($order)[$s['part']->getId()]['required']);
        self::assertSame($snapshotId, $em->find(ProjectPosition::class, $position->getId())->getManufacturingSnapshot()->getId());
        self::assertCount(1, $em->getRepository(ManufacturingSnapshot::class)->findAll());
    }

    public function testSourceSlotDeletionPreservesConfigurationAndLabels(): void
    {
        $client = self::createClient();
        $s = $this->scenario();
        self::getContainer()->get(SystemTemplateSlotPositioner::class)->remove($s['slot']);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $position = $em->find(ProjectPosition::class, $s['position']->getId());
        self::assertCount(1, $position->getChildren());
        self::assertSame('Nested slot original', $position->getChildren()->first()->getSourceSlot()->getName());
        self::assertCount(2, $position->getSlots());
        $client->loginUser($em->getRepository(User::class)->findOneBy(['name' => 'admin']));
        $client->request('GET', '/en/production/customer-projects/'.$s['order']->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-order-section="positions"]', 'Nested original');
        self::assertSelectorTextNotContains('[data-order-section="positions"]', 'Detached');
        $client->request('GET', '/en/production/project-positions/'.$position->getId().'/configure');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('form[name="form"]', 'Nested slot original');
    }

    public function testMigrationPlanIsReadOnlyAndPreservesExistingPositions(): void
    {
        self::createClient();
        $s = $this->scenario();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();
        // Reproduce legacy rows without their new snapshot links.
        $connection->executeStatement('UPDATE production_project_positions SET manufacturing_snapshot_id = NULL, definition_key = NULL, source_slot_key = NULL');
        $connection->executeStatement('DELETE FROM production_manufacturing_snapshots');
        $before = $connection->fetchAllAssociative('SELECT * FROM production_project_positions ORDER BY id');
        $em->clear();
        $queries = self::getContainer()->get(ManufacturingSnapshotMigrationPlan::class)->statements();
        self::assertSame($before, $connection->fetchAllAssociative('SELECT * FROM production_project_positions ORDER BY id'));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM production_manufacturing_snapshots'));
        foreach ($queries as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
        $after = $connection->fetchAllAssociative('SELECT * FROM production_project_positions ORDER BY id');
        foreach ($after as $index => &$row) {
            self::assertNotNull($row['manufacturing_snapshot_id']);
            self::assertNotNull($row['definition_key']);
            self::assertSame($row['source_slot_id'], $row['source_slot_key']);
            foreach (['manufacturing_snapshot_id', 'definition_key', 'source_slot_key'] as $column) {
                unset($row[$column], $before[$index][$column]);
            }
        }
        unset($row);
        self::assertSame($before, $after);
        $em->clear();
        $position = $em->find(ProjectPosition::class, $s['position']->getId());
        self::assertSame($position->getManufacturingSnapshot(), $position->getChildren()->first()->getManufacturingSnapshot());
        $baseBom = $em->find(ProjectBOMEntry::class, $s['baseBom']->getId());
        $baseBom->setQuantity(200);
        $em->flush();
        self::assertSame(2.0, $position->getDefinition()->getBom()[0]['quantity']);
    }

    public function testNewPositionUsesCurrentDefinitionAndExistingSelectionCannotBeForged(): void
    {
        $client = self::createClient();
        $s = $this->scenario();
        $client->loginUser($s['admin']);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $s['baseBom']->setQuantity(20);
        $em->flush();
        $crawler = $client->request('GET', '/en/production/project-positions/'.$s['position']->getId().'/edit');
        self::assertSelectorExists('[name="project_position[content]"][disabled]');
        $form = $crawler->filter('form[name="project_position"]')->form();
        $values = $form->getPhpValues();
        $values['project_position']['content'] = 'project_'.$s['optional']->getId();
        $client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects();
        $crawler = $client->request('GET', '/en/production/customer-projects/'.$s['order']->getId().'/positions/new');
        $client->submit($crawler->filter('form[name="project_position"]')->form(['project_position[content]' => 'system_'.$s['system']->getId(), 'project_position[name]' => 'New position']));
        self::assertResponseRedirects();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $old = $em->find(ProjectPosition::class, $s['position']->getId());
        $new = $em->getRepository(ProjectPosition::class)->findOneBy(['name' => 'New position']);
        self::assertSame(2.0, $old->getDefinition()->getBom()[0]['quantity']);
        self::assertSame(20.0, $new->getDefinition()->getBom()[0]['quantity']);
        self::assertNotSame($old->getManufacturingSnapshot()->getId(), $new->getManufacturingSnapshot()->getId());
        self::assertSame('system_'.$s['system']->getId(), $old->getDefinitionKey());
    }
}

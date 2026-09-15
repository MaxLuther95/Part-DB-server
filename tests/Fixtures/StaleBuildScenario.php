<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Entity\Parts\{Category, Part, PartLot, StorageLocation};
use App\Entity\Production\{Customer, CustomerProject, CustomerProjectStatus, ProductionProject, ProjectAccessory, ProjectPosition, SerialNumberRange};
use App\Entity\ProjectSystem\{Project, ProjectBOMEntry};
use App\Services\Production\ProductionBuildWorkflow;
use Doctrine\ORM\EntityManagerInterface;

/** Synthetic entities for testing changes made while a build wizard is open. */
final class StaleBuildScenario
{
    /** @return array<string, mixed> */
    public static function create(EntityManagerInterface $em, ProductionBuildWorkflow $workflow): array
    {
        $site = (new StorageLocation())->setName('Stale build test site');
        $part = (new Part())->setName('Stale build test component')->setCategory($em->find(Category::class, 1));
        $lot = (new PartLot())->setPart($part)->setStorageLocation($site)->setAmount(10);
        $part->addPartLot($lot);
        $original = (new Project())->setName('Stale build original type');
        $replacement = (new Project())->setName('Stale build replacement type');
        $bom = (new ProjectBOMEntry())->setPart($part)->setQuantity(2);
        $original->addBomEntry($bom);
        $customer = (new Customer())->setName('Stale build customer')->setCustomerNumber('STALE-C');
        $production = (new ProductionProject())->setName('Stale build project')->setProjectNumber('STALE-P');
        $order = (new CustomerProject())->setName('Stale build order')->setProjectNumber('STALE-O')
            ->setCustomer($customer)->setProductionProject($production)->setProductionSite($site)
            ->setStatus(CustomerProjectStatus::InProduction);
        foreach ([$site, $part, $lot, $original, $replacement, $bom, $customer, $production, $order] as $entity) {
            $em->persist($entity);
        }
        $em->flush(); // Order positions reference already saved manufacturing definitions.
        $position = (new ProjectPosition())->setName('Stale build position')->setCustomerProject($order)->setTemplateProject($original);
        $accessory = (new ProjectAccessory())->setCustomerProject($order)->setProjectPosition($position)->setPart($part)->setQuantity(1);
        $range = (new SerialNumberRange())->setName('Stale build identifiers')->setPrefix('STL')->addProject($original)->addProject($replacement);
        foreach ([$site, $part, $lot, $original, $replacement, $bom, $customer, $production, $order, $position, $accessory, $range] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $draft = $workflow->createDraft($original, $position);
        $draft['site_id'] = $site->getId();
        $draft['details']['n0'] = ['serial' => 'STL-0001', 'confirmed_serial' => 'STL-0001', 'notes' => 'Reviewed synthetic build', 'status' => 'in_progress'];
        $plan = $workflow->createMaterialPlan($draft, $site);
        $draft['lots'] = $workflow->allocateAvailableLots($plan);
        $draft['materials_taken'][(string) $part->getId()] = true;

        return compact('site', 'part', 'lot', 'original', 'replacement', 'bom', 'customer', 'production', 'order', 'position', 'accessory', 'range', 'draft');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Entity\Parts\Category;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartLot;
use App\Entity\Parts\StorageLocation;
use App\Entity\Parts\Supplier;
use App\Entity\PriceInformations\Orderdetail;
use App\Entity\Production\Customer;
use App\Entity\Production\CustomerProject;
use App\Entity\Production\CustomerProjectStatus;
use App\Entity\Production\ProductionProject;
use App\Entity\Production\ProjectAccessory;
use App\Entity\Production\ProjectMaterialReservation;
use Doctrine\ORM\EntityManagerInterface;

/** Synthetic fixtures only. */
final class RequiredPartsScenario
{
    /** @return array<string, mixed> */
    public static function create(EntityManagerInterface $em): array
    {
        $siteA = (new StorageLocation())->setName('FILTER Hamburg');
        $binA = (new StorageLocation())->setName('FILTER Shelf')->setParent($siteA);
        $siteB = (new StorageLocation())->setName('FILTER Berlin');
        $supplierX = (new Supplier())->setName('FILTER Distributor X');
        $supplierY = (new Supplier())->setName('FILTER Distributor Y');
        $category = $em->getRepository(Category::class)->find(1);
        $part = (new Part())->setName('FILTER Shared component')->setCategory($category);
        $other = (new Part())->setName('FILTER Other component')->setCategory($category);
        $obsolete = (new Part())->setName('FILTER Obsolete source')->setCategory($category);
        $lotA = (new PartLot())->setPart($part)->setStorageLocation($binA)->setAmount(5);
        $lotB = (new PartLot())->setPart($part)->setStorageLocation($siteB)->setAmount(20);
        $unknown = (new PartLot())->setPart($part)->setStorageLocation($binA)->setAmount(100)->setInstockUnknown(true);
        foreach ([$lotA, $lotB, $unknown] as $lot) {
            $part->addPartLot($lot);
        }
        foreach ([$siteA, $binA, $siteB, $supplierX, $supplierY, $part, $other, $obsolete, $lotA, $lotB, $unknown] as $entity) {
            $em->persist($entity);
        }
        foreach ([[$part, $supplierX, 'X-1', false], [$part, $supplierX, 'X-2', false], [$part, $supplierY, 'Y-1', false], [$other, $supplierY, 'Y-2', false], [$obsolete, $supplierX, 'OLD', true]] as [$p, $supplier, $number, $isObsolete]) {
            $detail = (new Orderdetail())->setSupplier($supplier)->setSupplierpartnr($number)->setObsolete($isObsolete);
            $p->addOrderdetail($detail);
            $em->persist($detail);
        }
        $customer = (new Customer())->setName('FILTER Customer')->setCustomerNumber('FILTER-C');
        $project = (new ProductionProject())->setName('FILTER Production project')->setProjectNumber('FILTER-P');
        $em->persist($customer);
        $em->persist($project);
        $orders = [];
        foreach ([['A1', $siteA, 7, CustomerProjectStatus::Commissioned], ['A2', $siteA, 5, CustomerProjectStatus::InProduction], ['B1', $siteB, 4, CustomerProjectStatus::Commissioned], ['PLAN', $siteA, 100, CustomerProjectStatus::Planning]] as [$number, $site, $quantity, $status]) {
            $order = (new CustomerProject())->setProjectNumber('FILTER-'.$number)->setName('FILTER '.$number)->setCustomer($customer)->setProductionProject($project)->setProductionSite($site)->setStatus($status);
            $em->persist($order);
            $em->persist((new ProjectAccessory())->setCustomerProject($order)->setPart($part)->setQuantity($quantity));
            $orders[$number] = $order;
        }
        foreach ([$other, $obsolete] as $p) {
            $em->persist((new ProjectAccessory())->setCustomerProject($orders['A1'])->setPart($p)->setQuantity(2));
        }
        $own = (new ProjectMaterialReservation())->setCustomerProject($orders['A1'])->setPart($part)->setSourcePartLot($lotA)->setSite($siteA)->setQuantity(1);
        $foreign = (new ProjectMaterialReservation())->setCustomerProject($orders['B1'])->setPart($part)->setSourcePartLot($lotA)->setSite($siteB)->setQuantity(4);
        $em->persist($own);
        $em->persist($foreign);
        $em->flush();

        return compact('siteA', 'binA', 'siteB', 'supplierX', 'supplierY', 'part', 'other', 'obsolete', 'lotA', 'lotB', 'orders', 'own', 'foreign');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Controller\Production;

use App\Entity\Production\ProjectMaterialAllocation;
use App\Entity\UserSystem\User;
use App\Tests\Fixtures\RequiredPartsScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RequiredPartsControllerTest extends WebTestCase
{
    public function testCombinedSiteAndDistributorFiltersRespectStockReservationsAndPurchasingSources(): void
    {
        $client = $this->adminClient();
        $data = RequiredPartsScenario::create(self::getContainer()->get(EntityManagerInterface::class));
        $query = ['site' => $data['siteA']->getId(), 'supplier' => $data['supplierX']->getId(), 'missing' => '1'];
        $client->request('GET', '/en/production/required-parts', $query);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('input[name="q"]');
        self::assertSelectorCount(1, 'tr[data-part-id]');
        $row = 'tr[data-part-id="'.$data['part']->getId().'"]';
        self::assertSelectorTextSame($row.' [data-quantity="required"]', '12');
        self::assertSelectorTextSame($row.' [data-quantity="free"]', '0');
        self::assertSelectorTextSame($row.' [data-quantity="to-order"]', '11');
        self::assertSelectorTextNotContains($row, 'FILTER-B1');
        self::assertSelectorTextNotContains($row, 'FILTER-PLAN');
        self::assertSelectorTextNotContains($row, 'Distributor Y');
        self::assertSelectorExists('select[name="site"] option[value="'.$data['siteA']->getId().'"][selected]');
        self::assertSelectorExists('select[name="supplier"] option[value="'.$data['supplierX']->getId().'"][selected]');

        $client->request('GET', '/en/production/required-parts', ['missing' => '0', 'supplier' => $data['supplierX']->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame($row.' [data-quantity="required"]', '16');
        self::assertSelectorTextSame($row.' [data-quantity="to-order"]', '0');
        self::assertSelectorCount(1, 'tr[data-part-id]');
        $client->request('GET', '/en/production/required-parts', ['supplier' => $data['supplierX']->getId(), 'missing' => '1']);
        self::assertSelectorNotExists('tr[data-part-id]');
        $client->request('GET', '/en/production/required-parts', ['site' => $data['siteA']->getId(), 'supplier' => $data['supplierY']->getId(), 'missing' => '1', 'q' => 'old search must not filter']);
        self::assertSelectorCount(2, 'tr[data-part-id]');
        self::assertSelectorNotExists('tr[data-part-id="'.$data['obsolete']->getId().'"]');
    }

    public function testFreeStockIsCountedOnceAndExcessAllocationDoesNotCoverAnotherOrder(): void
    {
        $client = $this->adminClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $data = RequiredPartsScenario::create($em);
        $em->remove($data['own']);
        $em->remove($data['foreign']);
        $em->flush();
        $query = ['site' => $data['siteA']->getId(), 'supplier' => $data['supplierX']->getId(), 'missing' => '1'];
        $client->request('GET', '/en/production/required-parts', $query);
        $row = 'tr[data-part-id="'.$data['part']->getId().'"]';
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame($row.' [data-quantity="free"]', '5');
        self::assertSelectorTextSame($row.' [data-quantity="to-order"]', '7');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $allocation = (new ProjectMaterialAllocation())->setCustomerProject($em->find(\App\Entity\Production\CustomerProject::class, $data['orders']['A1']->getId()))->setPart($em->find(\App\Entity\Parts\Part::class, $data['part']->getId()))->setQuantity(100);
        $em->persist($allocation);
        $lot = $em->find(\App\Entity\Parts\PartLot::class, $data['lotA']->getId());
        $lot->setAmount(0);
        $em->flush();
        $client->request('GET', '/en/production/required-parts', $query);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame($row.' [data-quantity="to-order"]', '5');
    }

    public function testInvalidFiltersAreRejectedAndReadPermissionsAreEnforced(): void
    {
        $client = $this->adminClient();
        $data = RequiredPartsScenario::create(self::getContainer()->get(EntityManagerInterface::class));
        foreach ([['site' => '2147483647'], ['site' => $data['binA']->getId()], ['supplier' => '2147483647'], ['missing' => 'invalid']] as $query) {
            $client->request('GET', '/en/production/required-parts', $query + ['missing' => '1']);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorNotExists('tr[data-part-id]');
        }
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->getPermissions()->setPermissionValue('storelocations', 'read', false);
        $admin->getPermissions()->setPermissionValue('suppliers', 'read', false);
        $em->flush();
        $client->request('GET', '/en/production/required-parts');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('select[name="site"]');
        self::assertSelectorNotExists('select[name="supplier"]');
        self::assertSelectorTextNotContains('[data-required-parts-table]', 'Distributor X');
        $client->catchExceptions(true);
        foreach ([['site' => $data['siteA']->getId()], ['supplier' => $data['supplierX']->getId()]] as $query) {
            $client->request('GET', '/en/production/required-parts', $query);
            self::assertResponseStatusCodeSame(403);
        }
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->getPermissions()->setPermissionValue('production_material', 'read', false);
        $em->flush();
        $client->request('GET', '/en/production/required-parts');
        self::assertResponseStatusCodeSame(403);
    }

    public function testCreateImportButtonsAndMyOrdersNavigation(): void
    {
        $client = $this->adminClient();
        $client->request('GET', '/en/production/projects');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#content a.btn-success[href="/en/production/projects/new"]');
        $client->request('GET', '/en/production/customer-projects');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#content a.btn-success[href="/en/production/customer-projects/new"]');
        self::assertSelectorExists('#content a.btn-outline-primary[href="/en/production/customer-projects/import"]');
        $client->request('GET', '/en/production/customer-projects/mine');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#content a[href="/en/production/customer-projects"]');
    }

    private function adminClient(): KernelBrowser
    {
        $client = self::createClient();
        $client->catchExceptions(false);
        $admin = self::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->setNeedPwChange(false);
        $client->loginUser($admin);

        return $client;
    }
}

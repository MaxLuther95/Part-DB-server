<?php

declare(strict_types=1);

namespace App\Tests\Controller\Production;

use App\Entity\Parts\{Category, Part, PartLot, StorageLocation};
use App\Entity\Production\{Customer, CustomerProject, CustomerProjectStatus, ProductionProject, ProjectAccessory, ProjectMaterialAllocation, ProjectMaterialReservation, ProjectPosition, SystemTemplate, SystemTemplateSlot};
use App\Entity\UserSystem\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OrderMaterialStatusTest extends WebTestCase
{
    public static function stockScenarios(): iterable
    {
        yield 'no site despite plentiful stock' => [false, 20, 50, 0, 0, 0, false, 'unknown', null];
        yield 'stock only elsewhere' => [true, 0, 50, 0, 0, 0, false, 'missing', 3];
        yield 'local stock covers one row but not whole order' => [true, 2, 50, 0, 0, 0, false, 'missing', 1];
        yield 'stock in child location covers whole order' => [true, 3, 50, 0, 0, 0, false, 'covered', 0];
        yield 'other order reserved stock' => [true, 10, 50, 8, 0, 0, false, 'missing', 1];
        yield 'own local reservation covers demand' => [true, 3, 50, 0, 3, 0, false, 'covered', 0];
        yield 'own reservation no longer covered' => [true, 1, 50, 0, 3, 0, false, 'missing', 2];
        yield 'provided stock covers demand' => [true, 0, 50, 0, 0, 3, false, 'covered', 0];
        yield 'unknown quantity cannot cover demand' => [true, 20, 50, 0, 0, 0, true, 'missing', 3];
        yield 'material figures hidden without permission' => [true, 20, 50, 0, 0, 0, false, 'hidden', null, false];
    }

    #[DataProvider('stockScenarios')]
    public function testAllOrderStockIndicatorsUseTheSitePlan(bool $hasSite, int $local, int $remote, int $otherReserved, int $ownReserved, int $allocated, bool $unknown, string $status, ?int $missing, bool $canReadMaterial = true): void
    {
        $client = self::createClient();
        $client->catchExceptions(false);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->setNeedPwChange(false);
        if (!$canReadMaterial) {
            $admin->getPermissions()->setPermissionValue('production_material', 'read', false);
            $admin->getPermissions()->setPermissionValue('users', 'edit_permissions', false);
            $admin->getPermissions()->setPermissionValue('groups', 'edit_permissions', false);
        }
        $client->loginUser($admin);
        $site = (new StorageLocation())->setName('Synthetic production site');
        $shelf = (new StorageLocation())->setName('Synthetic shelf')->setParent($site);
        $elsewhere = (new StorageLocation())->setName('Synthetic remote site');
        $part = (new Part())->setName('Synthetic shared component')->setCategory($em->find(Category::class, 1));
        $lot = (new PartLot())->setPart($part)->setStorageLocation($shelf)->setAmount($local)->setInstockUnknown($unknown);
        $remoteLot = (new PartLot())->setPart($part)->setStorageLocation($elsewhere)->setAmount($remote);
        $part->addPartLot($lot);
        $part->addPartLot($remoteLot);
        $customer = (new Customer())->setName('Synthetic status customer')->setCustomerNumber('STATUS-C');
        $project = (new ProductionProject())->setName('Synthetic status project')->setProjectNumber('STATUS-P');
        $order = (new CustomerProject())->setProjectNumber('STATUS-O')->setCustomer($customer)->setProductionProject($project)
            ->setStatus(CustomerProjectStatus::Commissioned)->setProductionSite($hasSite ? $site : null);
        $otherOrder = (new CustomerProject())->setProjectNumber('STATUS-OTHER')->setCustomer($customer)->setProductionProject($project);
        $template = (new SystemTemplate())->setName('Synthetic status system');
        $slot = (new SystemTemplateSlot())->setName('Synthetic part slot');
        $slot->addAllowedPart($part);
        $template->addSlot($slot);
        foreach ([$site, $shelf, $elsewhere, $part, $lot, $remoteLot, $customer, $project, $order, $otherOrder, $template, $slot] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $root = (new ProjectPosition())->setCustomerProject($order)->setSystemTemplate($template)->setName('Synthetic root');
        $child = (new ProjectPosition())->setCustomerProject($order)->setSystemTemplate($template)->setName('Synthetic child')->setParent($root)->setPosition(1);
        $root->addChild($child);
        // Three occurrences of one part: additional accessory, slot, and nested detached assignment.
        $extra = (new ProjectAccessory())->setCustomerProject($order)->setPart($part)->setQuantity(1)->setNote('Keep this note');
        $slotted = (new ProjectAccessory())->setProjectPosition($root)->setSourceSlot($slot)->setPart($part)->setQuantity(1);
        $detached = (new ProjectAccessory())->setProjectPosition($child)->setPart($part)->setQuantity(1);
        foreach ([$root, $child, $extra, $slotted, $detached] as $entity) {
            $em->persist($entity);
        }
        foreach ([[$order, $ownReserved], [$otherOrder, $otherReserved]] as [$owner, $quantity]) {
            if ($quantity > 0) {
                $em->persist((new ProjectMaterialReservation())->setCustomerProject($owner)->setPart($part)->setSourcePartLot($lot)->setSite($site)->setQuantity($quantity));
            }
        }
        if ($allocated > 0) {
            $em->persist((new ProjectMaterialAllocation())->setCustomerProject($order)->setPart($part)->setSourcePartLot($lot)->setQuantity($allocated));
        }
        $em->flush();
        $orderId = $order->getId();
        $em->clear();

        $crawler = $client->request('GET', '/en/production/customer-projects/'.$orderId);
        self::assertResponseIsSuccessful();
        if (!$canReadMaterial) {
            self::assertSelectorNotExists('[data-material-status], [data-order-section="material"]');
            self::assertSelectorTextContains('[data-order-section="accessories"] tbody td:nth-child(3)', '–');
            return;
        }
        self::assertCount(3, $crawler->filter('[data-material-status]'));
        self::assertCount(3, $crawler->filter('[data-material-status="'.$status.'"]'));
        self::assertCount(2, $crawler->filter('[data-order-section="positions"] [data-material-status="'.$status.'"]'));
        self::assertCount(1, $crawler->filter('[data-order-section="accessories"] [data-material-status="'.$status.'"]'));
        self::assertSame('Keep this note', $crawler->filter('[data-order-section="accessories"] .fa-note-sticky')->attr('title'));
        if (!$hasSite) {
            self::assertSelectorTextContains('[data-material-status]', 'Production site not set');
            self::assertSelectorNotExists('[data-material-status].bg-success, [data-material-status].bg-danger');
            self::assertSelectorNotExists('[data-order-section="material"] .bg-success, [data-order-section="material"] .bg-danger');
        } else {
            foreach ($crawler->filter('[data-material-status]') as $badge) {
                self::assertStringContainsString('Total order demand for this part: 3.', $badge->getAttribute('title'));
                self::assertStringContainsString('Synthetic production site', $badge->getAttribute('title'));
                self::assertSame($missing > 0 ? 'Missing for order: '.$missing : 'Order demand covered', trim($badge->textContent));
            }
            self::assertSelectorTextContains('[data-order-section="material"] tbody tr td:nth-child(5)', (string) $missing);
        }
    }
}

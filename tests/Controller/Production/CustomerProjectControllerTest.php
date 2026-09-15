<?php

declare(strict_types=1);

namespace App\Tests\Controller\Production;

use App\Entity\Production\Customer;
use App\Entity\Production\CustomerProject;
use App\Entity\Production\ProductionProject;
use App\Entity\UserSystem\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CustomerProjectControllerTest extends WebTestCase
{
    public function testOrderSummaryAndOptionalDeliveryDateForms(): void
    {
        $client = self::createClient();
        $client->catchExceptions(false);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $entityManager->getRepository(User::class)->findOneBy([
            'name' => 'admin',
        ]);
        self::assertInstanceOf(User::class, $admin);
        $admin->setNeedPwChange(false);
        $client->loginUser($admin);

        $suffix = bin2hex(random_bytes(4));
        $customer = (new Customer())
            ->setCustomerNumber('UI-'.$suffix)
            ->setName('UI customer '.$suffix);
        $productionProject = (new ProductionProject())
            ->setProjectNumber('UI-P-'.$suffix)
            ->setName('UI project '.$suffix);
        $order = (new CustomerProject())
            ->setProjectNumber('UI-O-'.$suffix)
            ->setName('UI order '.$suffix)
            ->setDescription("Order description\n<script>alert('not executable')</script>")
            ->setCustomer($customer)
            ->setProductionProject($productionProject)
            ->setPlannedDeliveryDate(new \DateTimeImmutable('2026-10-15'));
        $entityManager->persist($customer);
        $entityManager->persist($productionProject);
        $entityManager->persist($order);
        $entityManager->flush();

        $crawler = $client->request('GET', '/en/production/customer-projects/'.$order->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-order-description]'));
        self::assertCount(1, $crawler->filter('[data-order-description] + [data-order-workflow]'));
        self::assertCount(1, $crawler->filter('[data-order-description]')->previousAll()->filter('h1'));
        self::assertSelectorTextContains('[data-order-description]', "<script>alert('not executable')</script>");
        self::assertCount(1, $crawler->filter('[data-order-description] br'));
        self::assertCount(0, $crawler->filter('[data-order-description] script'));
        self::assertCount(5, $crawler->filter('[data-order-summary]'));
        self::assertCount(1, $crawler->filter('[data-order-summary="planned-delivery"]'));
        self::assertCount(0, $crawler->filter('[data-order-summary="order-number"]'));
        self::assertCount(0, $crawler->filter('[data-order-summary="production-site"]'));
        self::assertSelectorTextContains('[data-order-summary="planned-delivery"]', 'Planned delivery date');
        self::assertSelectorTextContains('[data-order-summary="planned-delivery"]', '2026');
        self::assertStringNotContainsString('Imported order positions', $client->getResponse()->getContent() ?: '');
        self::assertStringNotContainsString('History', $client->getResponse()->getContent() ?: '');
        self::assertCount(2, $crawler->filter('table[data-production-position-tree] thead th.text-center'));
        self::assertCount(2, $crawler->filter('table[data-order-build-instances-table] thead th.text-center'));
        self::assertSame(
            ['positions', 'accessories', 'notes', 'material', 'build-instances', 'attachments'],
            $crawler->filter('[data-order-section]')
                ->each(
                    static fn ($node): string => (string) $node->attr('data-order-section'),
                ),
        );

        $crawler = $client->request('GET', '/en/production/customer-projects/'.$order->getId().'/edit');
        self::assertResponseIsSuccessful();
        $deliveryDate = $crawler->filter('input[name="customer_project[plannedDeliveryDate]"]');
        self::assertCount(1, $deliveryDate);
        self::assertSame('2026-10-15', $deliveryDate->attr('value'));
        self::assertFalse($deliveryDate->matches('[required]'));
        $form = $crawler->selectButton('Save')
            ->form();
        $form['customer_project[plannedDeliveryDate]'] = '';
        $form['customer_project[description]'] = '';
        $client->submit($form);
        self::assertResponseRedirects('/en/production/customer-projects/'.$order->getId());
        $savedOrder = self::getContainer()
            ->get(EntityManagerInterface::class)
            ->getRepository(CustomerProject::class)
            ->find($order->getId());
        self::assertInstanceOf(CustomerProject::class, $savedOrder);
        self::assertNull($savedOrder->getPlannedDeliveryDate());
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-order-description]');
        self::assertSelectorExists('[data-order-workflow]');

        $crawler = $client->request('GET', '/en/production/customer-projects/new');
        self::assertResponseIsSuccessful();
        $deliveryDate = $crawler->filter('input[name="customer_project[plannedDeliveryDate]"]');
        self::assertCount(1, $deliveryDate);
        self::assertFalse($deliveryDate->matches('[required]'));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Controller\Production;

use App\Entity\Parts\Part;
use App\Entity\Production\{Customer, CustomerProject, CustomerProjectStatus, OrderImportLine, OrderImportLineDisposition, OrderImportMapping, OrderPositionUnit, ProductionProject, ProjectAccessory, ProjectPosition, SystemTemplate};
use App\Entity\ProjectSystem\Project;
use App\Entity\UserSystem\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OrderImportLineControllerTest extends WebTestCase
{
    private function seed(): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->setNeedPwChange(false);
        $customer = (new Customer())->setName('Synthetic customer')->setCustomerNumber('OPEN-C');
        $project = (new ProductionProject())->setName('Synthetic project')->setProjectNumber('OPEN-P');
        $order = (new CustomerProject())->setName('Synthetic order')->setOrderNumber('OPEN-O')->setCustomer($customer)->setProductionProject($project)->setStatus(CustomerProjectStatus::Commissioned);
        $line = (new OrderImportLine())->setOrder($order)->setDescription('Custom item <script>unsafe</script>')->setLineNumber(7)->setQuantity(2);
        foreach ([$customer, $project, $order, $line] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return [$admin, $order, $line];
    }

    public function testPendingNotesCompletionAndStaleActions(): void
    {
        $client = self::createClient();
        [$admin, $order, $line] = $this->seed();
        $client->loginUser($admin);
        $url = '/en/production/customer-projects/'.$order->getId();
        $crawler = $client->request('GET', $url);
        self::assertSelectorExists('[data-order-section="positions"] [data-import-line]');
        self::assertSelectorNotExists('[data-order-section="accessories"] [data-import-line]');
        self::assertSelectorNotExists('[data-import-line] script');
        self::assertSelectorTextContains('[data-import-line]', 'Assignment pending');
        $note = $crawler->filter('[data-import-line] form')->form();
        $edit = $client->request('GET', $url.'/edit');
        $client->submit($edit->filter('form[name="customer_project"]')->form(['customer_project[status]' => '3']));
        self::assertSelectorTextContains('form[name="customer_project"]', 'Open order positions must be assigned');
        $client->submit($note);
        self::assertResponseRedirects($url);
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('[data-import-line]', 'Information only');
        $reopen = $crawler->filter('[data-import-line] form')->form();
        // The old form must not overwrite the current classification.
        $client->submit($note);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'inzwischen verändert');
        $edit = $client->request('GET', $url.'/edit');
        $client->submit($edit->filter('form[name="customer_project"]')->form(['customer_project[status]' => '3']));
        self::assertResponseRedirects($url);
        $client->submit($reopen);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Abgeschlossene');
        self::assertSelectorTextContains('[data-import-line]', 'Information only');
        self::assertSelectorNotExists('[data-import-line] form');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(CustomerProjectStatus::Completed, $em->find(CustomerProject::class, $order->getId())->getStatus());
    }

    public static function targets(): iterable
    {
        yield 'stock part' => ['part'];
        yield 'native project' => ['templateProject'];
        yield 'system template' => ['systemTemplate'];
    }

    #[DataProvider('targets')]
    public function testAssignmentCreatesTheCorrectItemsOnlyOnce(string $field): void
    {
        $client = self::createClient();
        [$admin, $order, $line] = $this->seed();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $target = match ($field) {
            'part' => $em->getRepository(Part::class)->findOneBy([]),
            'templateProject' => (new Project())->setName('Synthetic build target'),
            default => (new SystemTemplate())->setName('Synthetic system target')->setOrderUnit(OrderPositionUnit::Piece),
        };
        $em->persist($target);
        $em->flush();
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/en/production/import-lines/'.$line->getId().'/assign');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="order_import_line_assignment"]')->form(['order_import_line_assignment['.$field.']' => (string) $target->getId()]);
        $client->submit($form);
        self::assertResponseRedirects('/en/production/customer-projects/'.$order->getId());
        $client->followRedirect();
        self::assertSelectorNotExists('[data-import-line]');
        $client->submit($form);
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'inzwischen verändert');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(OrderImportLineDisposition::Assigned, $em->find(OrderImportLine::class, $line->getId())->getDisposition());
        $positions = $em->getRepository(ProjectPosition::class)->findBy(['customerProject' => $order->getId()]);
        $accessories = $em->getRepository(ProjectAccessory::class)->findBy(['customerProject' => $order->getId()]);
        self::assertCount('part' === $field ? 0 : 2, $positions);
        self::assertCount('part' === $field ? 1 : 0, $accessories);
        if ('part' === $field) {
            self::assertSame(2, $accessories[0]->getQuantity());
        } else {
            self::assertSame([0, 1], array_map(static fn(ProjectPosition $position): int => $position->getPosition(), $positions));
            self::assertFalse($em->find(CustomerProject::class, $order->getId())->isReadyForCompletion());
        }
    }

    public function testInvalidSelectionAndCsrfCannotChangePosition(): void
    {
        $client = self::createClient();
        [$admin, $order, $line] = $this->seed();
        $client->loginUser($admin);
        $url = '/en/production/import-lines/'.$line->getId();
        $crawler = $client->request('GET', $url.'/assign');
        $client->submit($crawler->filter('form[name="order_import_line_assignment"]')->form());
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="order_import_line_assignment"]', 'Bitte genau eine');
        $client->request('POST', $url.'/note', ['expected' => 'pending', '_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(OrderImportLineDisposition::Pending, $em->find(OrderImportLine::class, $line->getId())->getDisposition());
    }

    public function testPermissionsAreRequiredForEveryAction(): void
    {
        $client = self::createClient();
        [$admin, $order, $line] = $this->seed();
        $admin->getPermissions()->setPermissionValue('production_orders', 'edit', false);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->loginUser($admin);
        $client->request('GET', '/en/production/customer-projects/'.$order->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-import-line]');
        self::assertSelectorNotExists('[data-import-line] form');
        foreach (['assign', 'note', 'pending'] as $action) {
            $client->request('POST', '/en/production/import-lines/'.$line->getId().'/'.$action);
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testUnitMismatchRequiresExplicitCorrection(): void
    {
        $client = self::createClient();
        [$admin, $order, $line] = $this->seed();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $line->setUnit(OrderPositionUnit::Set);
        $partId = $em->getRepository(Part::class)->findOneBy([])->getId();
        $em->flush();
        $client->loginUser($admin);
        $url = '/en/production/import-lines/'.$line->getId().'/assign';
        $crawler = $client->request('GET', $url);
        $form = $crawler->filter('form[name="order_import_line_assignment"]')->form(['order_import_line_assignment[part]' => (string) $partId]);
        $client->submit($form);
        $client->followRedirect();
        self::assertSelectorExists('[data-import-line]');
        self::assertSelectorTextContains('body', 'benötigt die Einheit');
        $crawler = $client->request('GET', $url);
        $client->submit($crawler->filter('form[name="order_import_line_assignment"]')->form(['order_import_line_assignment[part]' => (string) $partId, 'order_import_line_assignment[unit]' => 'pcs.']));
        $client->followRedirect();
        self::assertSelectorNotExists('[data-import-line]');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $accessory = $em->getRepository(ProjectAccessory::class)->findOneBy(['customerProject' => $order->getId()]);
        self::assertSame(2, $accessory->getQuantity());
    }

    public function testDeletingMappingDoesNotReopenAssignedPosition(): void
    {
        self::createClient();
        [, $order, $line] = $this->seed();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $mapping = (new OrderImportMapping())->setSourceDescription('Synthetic mapped item')->setPart($em->getRepository(Part::class)->findOneBy([]));
        $em->persist($mapping);
        $line->setMapping($mapping)->setDisposition(OrderImportLineDisposition::Assigned);
        $em->flush();
        $em->remove($mapping);
        $em->flush();
        $em->clear();
        $saved = $em->find(OrderImportLine::class, $line->getId());
        self::assertNull($saved->getMapping());
        self::assertSame(OrderImportLineDisposition::Assigned, $saved->getDisposition());
        self::assertSame([], $saved->getOrder()->getUnassignedImportLines());
    }
}

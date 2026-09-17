<?php

declare(strict_types=1);

namespace App\Tests\Controller\Production;

use App\Entity\Production\CustomerProject;
use App\Entity\UserSystem\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OrderImportControllerTest extends WebTestCase
{
    public static function requiredFields(): iterable
    {
        yield 'optional descriptions' => [null];
        yield 'German lump sum' => [null, true];
        yield 'mapped system with non-part BOM entry' => [null, false, 'system'];
        yield 'mapped native project' => [null, false, 'project'];
        yield 'mapped stock part accessory' => [null, false, 'part'];
        foreach (['order_number', 'customer_number', 'customer_name', 'project_number', 'order_date'] as $field) {
            yield $field => [$field];
        }
    }

    #[DataProvider('requiredFields')]
    public function testImportRequirementsAndSeparateNotes(?string $missingField, bool $german = false, ?string $mappedTarget = null): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->setNeedPwChange(false);
        if (null !== $mappedTarget) {
            $project = (new \App\Entity\ProjectSystem\Project())->setName('Synthetic service project');
            $entry = (new \App\Entity\ProjectSystem\ProjectBOMEntry())->setName('Synthetic assembly service')->setQuantity(2);
            $project->addBomEntry($entry);
            $system = (new \App\Entity\Production\SystemTemplate())->setName('Synthetic imported system')->setBaseProject($project);
            $mapping = (new \App\Entity\Production\OrderImportMapping())->setSourceDescription('Synthetic item');
            match ($mappedTarget) {
                'system' => $mapping->setSystemTemplate($system),
                'project' => $mapping->setTemplateProject($project),
                'part' => $mapping->setPart($em->getRepository(\App\Entity\Parts\Part::class)->findOneBy([])),
            };
            foreach ([$project, $entry, $system, $mapping] as $entity) {
                $em->persist($entity);
            }
        }
        $em->flush();
        $client->loginUser($admin);
        $path = tempnam(sys_get_temp_dir(), 'order-import-test-');
        rename($path, $path.'.pdf');
        $path .= '.pdf';
        $stream = implode("\n", array_map(static fn(string $text): string => 'BT ('.$text.') Tj ET', [
            'Document #: IMPORT-TEST-ORDER', 'Customer #: IMPORT-TEST-CUSTOMER',
            'Customer Name: Synthetic customer', 'Project #: IMPORT-TEST-PROJECT',
            'Date: 2026-09-15', 'Your Reference #: REFERENCE-TEST',
            '1 Synthetic item 1 pcs.', 'Synthetic position note <script>example</script>.', 'Second position detail.', 'total amount 100.00 EUR',
            'Synthetic delivery note <script>example</script>.',
        ]));
        if ($german) {
            $stream = strtr($stream, ['Document #:' => 'Dokument-Nr.:', 'Customer #:' => 'Kunden-Nr.:', 'Customer Name:' => 'Kundenname:', 'Project #:' => 'Projekt-Nr.:', 'Date: 2026-09-15' => 'Datum: 15.09.2026', 'Your Reference #:' => 'Ihre Referenz-Nr.:', '1 pcs.' => '1 psch', 'total amount' => 'Gesamtbetrag']);
        }
        file_put_contents($path, "%PDF-1.4\n1 0 obj << /Length ".strlen($stream)." >>\nstream\n".$stream."\nendstream\nendobj\ntrailer <<>>\n%%EOF");
        try {
            $upload = $client->request('GET', '/en/production/customer-projects/import');
            $client->submit($upload->selectButton('PDF auslesen')->form(['pdf' => $path]));
            self::assertResponseRedirects();
            $review = $client->followRedirect();
            self::assertResponseIsSuccessful();
            foreach (['order_number', 'customer_number', 'customer_name', 'project_number', 'order_date'] as $field) {
                self::assertSelectorExists('input[name="'.$field.'"][required]');
            }
            foreach (['order_name', 'project_name', 'customer_reference'] as $field) {
                self::assertSelectorExists('input[name="'.$field.'"]:not([required])');
            }
            self::assertSelectorExists('[data-import-notes] textarea[name="notes"]:not([required])');
            self::assertSelectorNotExists('textarea script');
            $form = $review->selectButton('Geprüften Auftrag speichern')->form([
                'order_name' => '', 'project_name' => '',
            ]);
            self::assertSame('REFERENCE-TEST', $form['customer_reference']->getValue());
            self::assertSame($german ? 'psch' : 'pcs.', $form['lines[0][unit]']->getValue());
            self::assertSame('Synthetic delivery note <script>example</script>.', $form['notes']->getValue());
            self::assertSame("Synthetic position note <script>example</script>.\nSecond position detail.", $form['lines[0][notes]']->getValue());
            if (null !== $mappedTarget) {
                $form['lines[0][notes]'] = str_repeat('A', 50001);
                $client->submit($form);
                self::assertResponseStatusCodeSame(422);
                self::assertSelectorTextContains('.alert-danger', '50.000');
                $form['lines[0][notes]'] = "Reviewed detail with \"quotes\" <script>example</script>.\n".str_repeat("Long multiline detail with Umlaut ä.\n", 12);
            }
            if (null !== $missingField) {
                $form[$missingField] = '   ';
            }
            $client->submit($form);
            self::assertResponseStatusCodeSame(null === $missingField ? 302 : 422);
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $order = $em->getRepository(CustomerProject::class)->findOneBy(['projectNumber' => 'IMPORT-TEST-ORDER']);
            if (null !== $missingField) {
                self::assertNull($order);
                self::assertSelectorExists('.alert-danger');
                self::assertSelectorExists('input[name="customer_reference"][value="REFERENCE-TEST"]');

                return;
            }
            self::assertNotNull($order);
            self::assertSame(trim($form['lines[0][notes]']->getValue()), $order->getImportLines()->first()->getNotes());
            self::assertSame($german ? 'psch' : 'pcs.', $order->getImportLines()->first()->getUnit());
            self::assertSame('', $order->getName());
            self::assertSame('', $order->getProductionProject()->getName());
            self::assertSame('REFERENCE-TEST', $order->getCustomerReference());
            self::assertSame('Synthetic delivery note <script>example</script>.', $order->getNotes());
            $client->followRedirect();
            if ('part' === $mappedTarget) {
                self::assertCount(0, $order->getPositions());
                self::assertCount(1, $order->getAccessories());
                self::assertSame($order->getImportLines()->first()->getNotes(), $order->getAccessories()->first()->getNote());
                self::assertSame($order->getAccessories()->first()->getNote(), $client->getCrawler()->filter('[data-order-section="accessories"] .fa-note-sticky')->attr('title'));
                self::assertSelectorNotExists('[data-order-section="accessories"] script');
                self::assertSelectorNotExists('[data-order-section="accessories"] small');
            } elseif (null !== $mappedTarget) {
                self::assertCount(1, $order->getPositions());
                self::assertSame($order->getImportLines()->first()->getNotes(), $order->getPositions()->first()->getNotes());
                self::assertSelectorExists('[data-production-position-row] .fa-note-sticky');
                self::assertSame($order->getPositions()->first()->getNotes(), $client->getCrawler()->filter('[data-production-position-row] .fa-note-sticky')->attr('title'));
                self::assertSelectorNotExists('[data-production-position-row] script');
                $definition = $order->getPositions()->first()->getDefinition();
                self::assertSame([['part_id' => null, 'name' => 'Synthetic assembly service', 'quantity' => 2.0]], $definition->getBom());
                self::assertSame([], $definition->getMaterialRows());
            } else {
                self::assertSelectorExists('[data-order-section="positions"] [data-import-line]');
                self::assertSelectorTextContains('[data-import-line]', 'Assignment pending');
            }
            self::assertSame('part' === $mappedTarget, $order->isReadyForCompletion());
            self::assertSelectorTextContains('[data-order-customer-reference]', 'REFERENCE-TEST');
            self::assertSelectorTextContains('[data-order-section="notes"]', 'Synthetic delivery note');
            self::assertSelectorNotExists('[data-order-section="notes"] script');
            $edit = $client->request('GET', '/en/production/customer-projects/'.$order->getId().'/edit');
            $client->submit($edit->filter('form[name="customer_project"]')->form());
            self::assertResponseRedirects();
        } finally {
            @unlink($path);
        }
    }
}

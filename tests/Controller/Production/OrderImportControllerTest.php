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
        foreach (['order_number', 'customer_number', 'customer_name', 'project_number', 'order_date'] as $field) {
            yield $field => [$field];
        }
    }

    #[DataProvider('requiredFields')]
    public function testImportRequirementsAndSeparateNotes(?string $missingField, bool $german = false): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->setNeedPwChange(false);
        $em->flush();
        $client->loginUser($admin);
        $path = tempnam(sys_get_temp_dir(), 'order-import-test-');
        rename($path, $path.'.pdf');
        $path .= '.pdf';
        $stream = implode("\n", array_map(static fn(string $text): string => 'BT ('.$text.') Tj ET', [
            'Document #: IMPORT-TEST-ORDER', 'Customer #: IMPORT-TEST-CUSTOMER',
            'Customer Name: Synthetic customer', 'Project #: IMPORT-TEST-PROJECT',
            'Date: 2026-09-15', 'Your Reference #: REFERENCE-TEST',
            '1 Synthetic item 1 pcs.', 'total amount 100.00 EUR',
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
            self::assertSame($german ? 'psch' : 'pcs.', $order->getImportLines()->first()->getUnit());
            self::assertSame('', $order->getName());
            self::assertSame('', $order->getProductionProject()->getName());
            self::assertSame('REFERENCE-TEST', $order->getCustomerReference());
            self::assertSame('Synthetic delivery note <script>example</script>.', $order->getNotes());
            $client->followRedirect();
            self::assertSelectorExists('[data-order-section="positions"] [data-import-line]');
            self::assertSelectorTextContains('[data-import-line]', 'Assignment pending');
            self::assertFalse($order->isReadyForCompletion());
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

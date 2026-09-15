<?php

declare(strict_types=1);

namespace App\Tests\Services\Production;

use App\Entity\Production\ProtocolRevisionStatus;
use App\Entity\Production\ProtocolTemplate;
use App\Services\Production\ProtocolTemplateExporter;
use App\Services\Production\ProtocolTemplateImporter;
use App\Tests\Fixtures\ProtocolTemplateImportExample;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProtocolTemplateImporterTest extends KernelTestCase
{
    public function testRoundTripPreservesDefinitionsButCreatesOnlyAnUnpublishedDraft(): void
    {
        self::bootKernel();
        $json = ProtocolTemplateImportExample::json();
        $source = json_decode($json, true);
        $importer = self::getContainer()->get(ProtocolTemplateImporter::class);
        $created = $importer->import($json, [0 => ['revision' => 7, 'name' => 'Imported round trip']]);
        self::assertCount(1, $created);
        $id = $created[0]->getId();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $template = $em->find(ProtocolTemplate::class, $id);
        self::assertSame('Imported round trip', $template->getName());
        self::assertCount(0, $template->getSystemTemplates());
        self::assertFalse($template->isActive());
        self::assertNull($template->getPublishedRevision());
        $draft = $template->getDraftRevision();
        self::assertSame(1, $draft->getRevisionNumber());
        self::assertSame(ProtocolRevisionStatus::Draft, $draft->getStatus());
        self::assertNull($draft->getPublishedAt());
        self::assertNull($draft->getPublishedBy());
        $exported = json_decode((new ProtocolTemplateExporter())->export([$draft]), true);
        self::assertSame($source['templates'][0]['revisions'][0]['sections'], $exported['templates'][0]['revisions'][0]['sections']);
        self::assertSame('Source note', $draft->getChangeNote());
    }

    public function testConflictRejectsTheWholeBatchWithoutChangingExistingTemplates(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $existing = (new ProtocolTemplate())->setName('Existing import name')->setDescription('Do not replace');
        $em->persist($existing);
        $em->flush();
        $count = $em->getRepository(ProtocolTemplate::class)->count([]);
        $data = json_decode(ProtocolTemplateImportExample::json(), true);
        $data['templates'][] = $data['templates'][0];
        try {
            self::getContainer()->get(ProtocolTemplateImporter::class)->import(json_encode($data), [
                0 => ['revision' => 7, 'name' => 'Must not be partially created'],
                1 => ['revision' => 7, 'name' => 'Existing import name'],
            ]);
            self::fail('A conflicting batch must not be imported.');
        } catch (\DomainException $exception) {
            self::assertSame('production.protocol.import.name_conflict', $exception->getMessage());
            self::assertSame($count, $em->getRepository(ProtocolTemplate::class)->count([]));
            self::assertSame('Do not replace', $existing->getDescription());
        }
    }

    public function testMultipleTemplatesCanBeImportedTogether(): void
    {
        self::bootKernel();
        $data = json_decode(ProtocolTemplateImportExample::json(), true);
        $data['templates'][] = $data['templates'][0];
        $created = self::getContainer()->get(ProtocolTemplateImporter::class)->import(json_encode($data), [
            0 => ['revision' => 7, 'name' => 'Bulk import one'],
            1 => ['revision' => 7, 'name' => 'Bulk import two'],
        ]);
        self::assertCount(2, $created);
        self::assertNotSame($created[0]->getId(), $created[1]->getId());
        self::assertCount(1, $created[0]->getRevisions());
        self::assertCount(1, $created[1]->getRevisions());
    }

    public function testLateDatabaseFailureRollsBackEarlierItems(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();
        if (! $connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SQLitePlatform) {
            self::markTestSkipped('The fault-injection trigger uses SQLite syntax.');
        }
        $before = $connection->fetchOne('SELECT COUNT(*) FROM production_protocol_templates');
        $connection->executeStatement("CREATE TEMP TRIGGER protocol_import_test_failure BEFORE INSERT ON production_protocol_templates WHEN NEW.name = 'Fail second import' BEGIN SELECT RAISE(ABORT, 'Injected import failure'); END");
        $data = json_decode(ProtocolTemplateImportExample::json(), true);
        $data['templates'][] = $data['templates'][0];
        try {
            self::getContainer()->get(ProtocolTemplateImporter::class)->import(json_encode($data), [
                0 => ['revision' => 7, 'name' => 'Rollback first import'],
                1 => ['revision' => 7, 'name' => 'Fail second import'],
            ]);
            self::fail('The injected database failure must abort the import.');
        } catch (\Doctrine\DBAL\Exception) {
            self::assertSame($before, $connection->fetchOne('SELECT COUNT(*) FROM production_protocol_templates'));
            self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM production_protocol_templates WHERE name = 'Rollback first import'"));
        } finally {
            $connection->executeStatement('DROP TRIGGER IF EXISTS protocol_import_test_failure');
        }
    }
}

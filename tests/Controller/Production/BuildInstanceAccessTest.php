<?php

declare(strict_types=1);

namespace App\Tests\Controller\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\BuildInstanceAttachment;
use App\Entity\Production\DatasheetDocument;
use App\Entity\Production\DatasheetTemplate;
use App\Entity\Production\DatasheetTemplateRevision;
use App\Entity\Production\ProtocolRun;
use App\Entity\Production\ProtocolTemplate;
use App\Entity\Production\ProtocolTemplateRevision;
use App\Entity\ProjectSystem\Project;
use App\Entity\UserSystem\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BuildInstanceAccessTest extends WebTestCase
{
    public function testProductionSidebarRequiresAnAvailableProductionSource(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->setNeedPwChange(false);
        $admin->getPermissions()->setPermissionValue('projects', 'read', true);
        foreach (['production_orders', 'production_projects', 'production_customers', 'production_system_templates', 'production_build_instances', 'production_material', 'production_protocol_templates', 'production_datasheet_templates', 'production_import_mappings'] as $permission) {
            $admin->getPermissions()->setPermissionValue($permission, 'read', false);
        }
        $admin->getPermissions()->setPermissionValue('users', 'edit_permissions', false);
        $admin->getPermissions()->setPermissionValue('groups', 'edit_permissions', false);
        $em->flush();
        $client->loginUser($admin);
        $client->request('GET', '/en/');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#sidebar-panel-production');

        $admin->getPermissions()->setPermissionValue('production_protocol_templates', 'read', true);
        $em->flush();
        $client->loginUser($admin);
        $client->request('GET', '/en/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#sidebar-panel-production [data-mode="production"]');
    }

    public function testRelatedPagesAndDownloadsRequireTheInstanceReadPolicy(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->setNeedPwChange(false);
        $project = (new Project())->setName('Restricted build type');
        $instance = (new BuildInstance())->setSerialNumber('ACCESS-TEST')->setTemplateProject($project);
        $protocol = (new ProtocolTemplate())->setName('Restricted protocol');
        $protocolRevision = new ProtocolTemplateRevision();
        $protocol->addRevision($protocolRevision);
        $protocolRevision->publish($admin);
        $run = (new ProtocolRun())->setBuildInstance($instance)->setRevision($protocolRevision)->setNotes('Restricted note');
        $sheet = (new DatasheetTemplate())->setName('Restricted sheet')->setProductTitle('Test');
        $sheetRevision = new DatasheetTemplateRevision();
        $sheet->addRevision($sheetRevision);
        $sheetRevision->publish($admin);
        $document = (new DatasheetDocument())->setBuildInstance($instance)->setTemplate($sheet)->setRevision($sheetRevision)->setStoredFilename(str_repeat('a', 48).'.pdf');
        $attachment = (new BuildInstanceAttachment())->setBuildInstance($instance)->setStoredFilename(str_repeat('b', 48).'.txt');
        foreach ([$project, $instance, $protocol, $run, $sheet, $document, $attachment] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/en/production/protocol-runs/'.$run->getId().'/edit');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Save draft')->form();
        $form['protocol_run[notes]'] = 'Forbidden update';

        $admin->getPermissions()->setPermissionValue('projects', 'read', false);
        $em->flush();
        $client->loginUser($admin);
        foreach ([
            '/build-instances/'.$instance->getId(),
            '/build-instances/'.$instance->getId().'/edit',
            '/protocol-runs/'.$run->getId(),
            '/protocol-runs/'.$run->getId().'/edit',
            '/build-instances/'.$instance->getId().'/datasheets/'.$sheet->getId().'/prepare',
            '/datasheet-revisions/'.$sheetRevision->getId().'/preview.pdf?build_instance='.$instance->getId(),
            '/build-instance-attachments/'.$attachment->getId().'/download',
            '/datasheets/'.$document->getId().'/download',
        ] as $path) {
            $client->request('GET', '/en/production'.$path);
            self::assertResponseStatusCodeSame(403, $path);
        }
        // This is a valid, previously obtained form/CSRF token, not a rejection
        // caused merely by an invalid token. The stored note must remain intact.
        $client->submit($form);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Restricted note', $em->getConnection()->fetchOne('SELECT notes FROM production_protocol_runs WHERE id = ?', [$run->getId()]));

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->getPermissions()->setPermissionValue('projects', 'read', true);
        $em->flush();
        $client->loginUser($admin);
        $client->request('GET', '/en/production/protocol-runs/'.$run->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Restricted note', $client->getResponse()->getContent());
    }

    public function testRestrictedInstalledChildCannotBeReadThroughParentDatasheet(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->setNeedPwChange(false);
        $admin->getPermissions()->setPermissionValue('projects', 'read', false);
        $system = (new \App\Entity\Production\SystemTemplate())->setName('Access parent system');
        $parent = (new BuildInstance())->setSerialNumber('ACCESS-PARENT')->setSystemTemplate($system);
        $project = (new Project())->setName('Restricted installed board');
        $child = (new BuildInstance())->setSerialNumber('ACCESS-CHILD')->setTemplateProject($project)->setParent($parent);
        $sheet = (new DatasheetTemplate())->setName('Parent sheet')->setProductTitle('Parent')->addSystemTemplate($system);
        $revision = new DatasheetTemplateRevision();
        $sheet->addRevision($revision);
        $revision->publish($admin);
        foreach ([$system, $project, $parent, $child, $sheet] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $client->loginUser($admin);
        $client->request('GET', '/en/production/build-instances/'.$parent->getId().'/datasheets/'.$sheet->getId().'/prepare');
        self::assertResponseStatusCodeSame(403);
        $admin->getPermissions()->setPermissionValue('projects', 'read', true);
        $em->flush();
        $client->loginUser($admin);
        $client->request('GET', '/en/production/build-instances/'.$parent->getId().'/datasheets/'.$sheet->getId().'/prepare');
        self::assertResponseIsSuccessful();
    }
}

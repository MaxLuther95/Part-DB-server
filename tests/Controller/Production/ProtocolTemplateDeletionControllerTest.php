<?php

declare(strict_types=1);

namespace App\Tests\Controller\Production;

use App\Entity\Production\{ProtocolTemplate, ProtocolTemplateRevision, ProtocolTemplateSection, ProtocolTemplateField, SystemTemplate};
use App\Entity\ProjectSystem\Project;
use App\Entity\UserSystem\User;
use App\Services\Production\ProtocolManager;
use App\Tests\Fixtures\ProtocolRunScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProtocolTemplateDeletionControllerTest extends WebTestCase
{
    public static function unusedStates(): iterable
    {
        yield 'draft' => [false];
        yield 'published without runs' => [true];
    }

    #[DataProvider('unusedStates')]
    public function testUnusedTemplateDeletionRemovesDefinitionsAndAssignmentsOnly(bool $published): void
    {
        $client = self::createClient();
        $client->catchExceptions(false);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->setNeedPwChange(false);
        $system = (new SystemTemplate())->setName('Retained system');
        $project = (new Project())->setName('Retained project');
        $template = (new ProtocolTemplate())->setName('Unused template')->addSystemTemplate($system)->addProject($project);
        $revision = new ProtocolTemplateRevision();
        $section = (new ProtocolTemplateSection())->setName('Unused section');
        $field = (new ProtocolTemplateField())->setLabel('Unused field');
        $section->addField($field);$revision->addSection($section);$template->addRevision($revision);
        if ($published) { $revision->publish(null); }
        foreach ([$system, $project, $template] as $entity) { $em->persist($entity); }
        $em->flush();
        $ids = ['template' => $template->getId(), 'revision' => $revision->getId(), 'section' => $section->getId(), 'field' => $field->getId(), 'system' => $system->getId(), 'project' => $project->getId()];
        $client->loginUser($admin);
        $url = '/en/production/protocol-templates/'.$ids['template'];
        $crawler = $client->request('GET', $url.'/edit');
        self::assertSelectorExists('button[form="production-delete-form"]');
        $client->submit($crawler->filter('#production-delete-form')->form());
        self::assertResponseRedirects('/en/production/protocol-templates');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        foreach (['template' => ProtocolTemplate::class, 'revision' => ProtocolTemplateRevision::class, 'section' => ProtocolTemplateSection::class, 'field' => ProtocolTemplateField::class] as $name => $class) {
            self::assertNull($em->find($class, $ids[$name]));
        }
        self::assertNotNull($em->find(SystemTemplate::class, $ids['system']));
        self::assertNotNull($em->find(Project::class, $ids['project']));
        foreach (['production_protocol_template_systems', 'production_protocol_template_projects'] as $table) {
            self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table.' WHERE protocol_template_id = ?', [$ids['template']]));
        }
    }

    public static function runStates(): iterable
    {
        foreach (['draft', 'completed', 'invalid'] as $state) { yield $state => [$state]; }
    }

    #[DataProvider('runStates')]
    public function testUsedTemplateDeletionPreservesAllExistingRecords(string $state): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $run = ProtocolRunScenario::create($em, self::getContainer()->get(ProtocolManager::class));
        if ('draft' !== $state) { $run->complete(null); }
        if ('invalid' === $state) { $run->invalidate('Synthetic invalidation', null); }
        $em->flush();
        $id = $run->getRevision()->getTemplate()->getId();
        $before = $this->definitionRows($em);
        $client->loginUser($em->getRepository(User::class)->findOneBy(['name' => 'admin']));
        $crawler = $client->request('GET', '/en/production/protocol-templates/'.$id);
        $client->submit($crawler->filter('form[action$="/delete"]')->form());
        self::assertResponseRedirects('/en/production/protocol-templates/'.$id);
        $client->followRedirect();
        self::assertStringContainsString('cannot be deleted', $client->getResponse()->getContent());
        self::assertSame($before, $this->definitionRows(self::getContainer()->get(EntityManagerInterface::class)));
    }

    public function testDeleteRequiresPostAndValidCsrf(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $run = ProtocolRunScenario::create($em, self::getContainer()->get(ProtocolManager::class));
        $client->loginUser($em->getRepository(User::class)->findOneBy(['name' => 'admin']));
        $url = '/en/production/protocol-templates/'.$run->getRevision()->getTemplate()->getId().'/delete';
        $client->request('GET', $url);self::assertResponseStatusCodeSame(405);
        $client->request('POST', $url, ['_token' => 'invalid']);self::assertResponseStatusCodeSame(403);
    }

    public static function deniedRoles(): iterable
    {
        yield 'ordinary user with delete permission' => ['user', true];
        yield 'administrator without delete permission' => ['admin', false];
    }

    #[DataProvider('deniedRoles')]
    public function testDeleteRequiresBothAdministrationAndDeletePermission(string $name, bool $canDelete): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $run = ProtocolRunScenario::create($em, self::getContainer()->get(ProtocolManager::class));
        $user = $em->getRepository(User::class)->findOneBy(['name' => $name]);
        $user->setNeedPwChange(false);
        $user->getPermissions()->setPermissionValue('production_protocol_templates', 'read', true);
        $user->getPermissions()->setPermissionValue('production_protocol_templates', 'delete', $canDelete);
        $em->flush();$client->loginUser($user);
        $url = '/en/production/protocol-templates/'.$run->getRevision()->getTemplate()->getId();
        $client->request('GET', $url);self::assertSelectorNotExists('form[action$="/delete"]');
        $client->request('POST', $url.'/delete');self::assertResponseStatusCodeSame(403);
    }

    private function definitionRows(EntityManagerInterface $em): array
    {
        $rows = [];
        foreach (['production_protocol_templates', 'production_protocol_template_revisions', 'production_protocol_template_sections', 'production_protocol_template_fields', 'production_protocol_template_systems', 'production_protocol_runs', 'production_protocol_answers'] as $table) {
            $rows[$table] = $em->getConnection()->fetchAllAssociative('SELECT * FROM '.$table);
        }
        return $rows;
    }
}

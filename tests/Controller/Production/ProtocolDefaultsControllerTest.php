<?php

declare(strict_types=1);

namespace App\Tests\Controller\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\ProtocolRun;
use App\Entity\Production\ProtocolTemplate;
use App\Entity\Production\ProtocolTemplateRevision;
use App\Entity\Production\SystemTemplate;
use App\Entity\UserSystem\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProtocolDefaultsControllerTest extends WebTestCase
{
    public function testProjectAssignmentIsExplicitUniqueAndVisibleFromRevisionEditor(): void
    {
        $client = $this->adminClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $project = (new \App\Entity\ProjectSystem\Project())->setName('Board build project');
        $system = (new SystemTemplate())->setName('Parent device');
        $template = $this->template('Board protocol');
        $other = $this->template('Conflicting protocol');
        $instance = (new BuildInstance())->setSerialNumber('PROJECT-BOARD')->setTemplateProject($project);
        $parent = (new BuildInstance())->setSerialNumber('PARENT-DEVICE')->setSystemTemplate($system);
        foreach ([$project, $system, $template, $other, $instance, $parent] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $projectId = $project->getId();
        $templateId = $template->getId();
        $otherId = $other->getId();
        $instanceId = $instance->getId();
        $parentId = $parent->getId();
        $crawler = $client->request('GET', '/en/production/protocol-templates/'.$templateId.'/edit');
        $form = $crawler->selectButton('Save draft')->form();
        $form['protocol_template[projects]']->select([(string) $projectId]);
        $client->submit($form);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('[data-protocol-project-assignments]', 'Board build project');

        $crawler = $client->request('GET', '/en/production/build-instances/'.$instanceId);
        self::assertCount(1, $crawler->filter('form[action$="/protocol-runs"]'));
        $client->submit($crawler->filter('form[action$="/protocol-runs"]')->form());
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('.card-header', 'Board protocol');
        $client->request('GET', '/en/production/build-instances/'.$parentId);
        self::assertSelectorNotExists('form[action$="/protocol-runs"]');

        $crawler = $client->request('GET', '/en/production/protocol-templates/'.$otherId.'/edit');
        $form = $crawler->selectButton('Save draft')->form();
        $form['protocol_template[projects]']->select([(string) $projectId]);
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.invalid-feedback', 'Board protocol');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertCount(0, $em->find(ProtocolTemplate::class, $otherId)->getProjects());
        $draft = self::getContainer()->get(\App\Services\Production\ProtocolManager::class)->getOrCreateDraft($em->find(ProtocolTemplate::class, $templateId));
        $em->persist($draft);
        $em->flush();
        $client->request('GET', '/en/production/protocol-revisions/'.$draft->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/en/production/protocol-templates/'.$templateId.'/edit"]');
    }

    public function testInactiveAndUnpublishedAssignmentsCannotCreateRuns(): void
    {
        $client = $this->adminClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $system = (new SystemTemplate())->setName('Unpublished system');
        $template = $this->template('Unpublished', false)->addSystemTemplate($system);
        $instance = (new BuildInstance())->setSerialNumber('UNPUBLISHED')->setSystemTemplate($system);
        foreach ([$system, $template, $instance] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $client->request('GET', '/en/production/build-instances/'.$instance->getId());
        self::assertSelectorNotExists('form[action$="/protocol-runs"]');
        $manager = self::getContainer()->get(\App\Services\Production\ProtocolManager::class);
        try {
            $manager->createRun($instance, $template->getDraftRevision(), null);
            self::fail('Draft template must not be usable.');
        } catch (\InvalidArgumentException) {
            self::assertCount(0, $instance->getProtocolRuns());
        }
        $template->getDraftRevision()->publish(null);
        $template->setActive(false);
        $this->expectException(\InvalidArgumentException::class);
        $manager->createRun($instance, $template->getPublishedRevision(), null);
    }

    public function testExplicitAssignmentCreationAndExistingRunsSurviveUnassignment(): void
    {
        $client = $this->adminClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $systemA = (new SystemTemplate())->setName('System A');
        $systemB = (new SystemTemplate())->setName('System B');
        $template = $this->template('Suggested measurements');
        $manual = $this->template('Manual alternative');
        $draftOnly = $this->template('Draft only', false);
        $inactive = $this->template('Inactive')->setActive(false);
        $instance = (new BuildInstance())->setSerialNumber('DEFAULTS-HTTP')->setSystemTemplate($systemA);
        foreach ([$systemA, $systemB, $template, $manual, $draftOnly, $inactive, $instance] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $templateId = $template->getId();
        $instanceId = $instance->getId();
        $systemIds = [(string) $systemA->getId(), (string) $systemB->getId()];
        $crawler = $client->request('GET', '/en/production/protocol-templates/'.$templateId.'/edit');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Save draft')->form();
        $form['protocol_template[systemTemplates]']->select($systemIds);
        $client->submit($form);
        self::assertResponseRedirects('/en/production/protocol-templates/'.$templateId);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-protocol-system-assignments]', 'System A');
        self::assertSelectorTextContains('[data-protocol-system-assignments]', 'System B');

        $crawler = $client->request('GET', '/en/production/build-instances/'.$instanceId);
        self::assertCount(0, $crawler->filter('select[name="template_id"]'));
        self::assertSelectorTextContains('[data-protocol-assignment]', 'Suggested measurements');
        self::assertCount(1, $crawler->filter('form[action$="/protocol-runs"]'));
        self::assertSame(0, self::getContainer()->get(EntityManagerInterface::class)->getRepository(ProtocolRun::class)->count(['buildInstance' => $instanceId]));

        $form = $crawler->filter('form[action$="/protocol-runs"]')->form();
        // A forged template ID cannot override the server-resolved assignment.
        $values = $form->getPhpValues();
        $values['template_id'] = $manual->getId();
        $client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextSame('textarea[name="protocol_run[notes]"]', '');
        self::assertSelectorTextContains('.card-header', 'Suggested measurements');
        $run = self::getContainer()->get(EntityManagerInterface::class)->getRepository(ProtocolRun::class)->findOneBy(['buildInstance' => $instanceId]);
        self::assertSame($template->getPublishedRevision()->getId(), $run->getRevision()->getId());
        $runId = $run->getId();

        // Replacing assignments must remove links without changing existing runs.
        $crawler = $client->request('GET', '/en/production/protocol-templates/'.$templateId.'/edit');
        $form = $crawler->selectButton('Save draft')->form();
        $form['protocol_template[systemTemplates]']->select([$systemIds[1]]);
        $client->submit($form);
        self::assertResponseRedirects();
        $crawler = $client->request('GET', '/en/production/build-instances/'.$instanceId);
        self::assertCount(0, $crawler->filter('form[action$="/protocol-runs"]'));
        self::assertSame(1, self::getContainer()->get(EntityManagerInterface::class)->getRepository(ProtocolRun::class)->count(['buildInstance' => $instanceId]));
        $client->request('GET', '/en/production/protocol-runs/'.$runId.'/edit');
        self::assertResponseIsSuccessful();
        $client->request('POST', '/en/production/build-instances/'.$instanceId.'/protocol-runs', $values);
        self::assertResponseRedirects('/en/production/build-instances/'.$instanceId);
        self::assertSame(1, self::getContainer()->get(EntityManagerInterface::class)->getRepository(ProtocolRun::class)->count(['buildInstance' => $instanceId]));
    }

    public function testInvalidAssignmentAndMissingPermissionsCannotChangeLinks(): void
    {
        $client = $this->adminClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $system = (new SystemTemplate())->setName('Private system');
        $template = $this->template('Permission test')->addSystemTemplate($system);
        $em->persist($system);
        $em->persist($template);
        $em->flush();
        $id = $template->getId();
        $url = '/en/production/protocol-templates/'.$id.'/edit';
        $crawler = $client->request('GET', $url);
        $values = $crawler->selectButton('Save draft')->form()->getPhpValues();
        $values['protocol_template']['systemTemplates'] = ['2147483647'];
        $client->request('POST', $url, $values);
        self::assertResponseStatusCodeSame(422);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        // Discard the invalid submitted form's in-memory changes, as on a new request.
        $em->clear();
        self::assertCount(1, $em->find(ProtocolTemplate::class, $id)->getSystemTemplates());
        $admin = $em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->getPermissions()->setPermissionValue('production_system_templates', 'read', false);
        $em->flush();
        $client->loginUser($admin);
        $crawler = $client->request('GET', $url);
        self::assertSelectorNotExists('select[name="protocol_template[systemTemplates][]"]');
        $form = $crawler->selectButton('Save draft')->form();
        $client->submit($form);
        self::assertResponseRedirects();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(1, $em->find(ProtocolTemplate::class, $id)->getSystemTemplates());
        $reader = $em->getRepository(User::class)->findOneBy(['name' => 'user']);
        $reader->setNeedPwChange(false);
        $client->loginUser($reader);
        $client->catchExceptions(true);
        $client->request('POST', $url, $values);
        self::assertResponseStatusCodeSame(403);
    }

    private function template(string $name, bool $published = true): ProtocolTemplate
    {
        $template = (new ProtocolTemplate())->setName($name);
        $revision = new ProtocolTemplateRevision();
        $template->addRevision($revision);
        if ($published) {
            $revision->publish(null);
        }

        return $template;
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

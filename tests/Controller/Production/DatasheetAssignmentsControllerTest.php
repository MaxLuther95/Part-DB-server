<?php

declare(strict_types=1);

namespace App\Tests\Controller\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\DatasheetTemplate;
use App\Entity\Production\DatasheetTemplateRevision;
use App\Entity\Production\SystemTemplate;
use App\Entity\ProjectSystem\Project;
use App\Entity\UserSystem\User;
use App\Repository\Production\DatasheetTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DatasheetAssignmentsControllerTest extends WebTestCase
{
    public function testMultipleAssignmentsCanBeSavedAndOnlyApplicablePublishedTemplatesAreOffered(): void
    {
        $client = $this->adminClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $systemA = (new SystemTemplate())->setName('Assigned system A');
        $systemB = (new SystemTemplate())->setName('Assigned system B');
        $project = (new Project())->setName('Assigned cable build project');
        $first = $this->template('First datasheet');
        $second = $this->template('Second datasheet')->addSystemTemplate($systemA)->addProject($project);
        $draft = $this->template('Draft datasheet', false)->addSystemTemplate($systemA);
        $inactive = $this->template('Inactive datasheet')->setActive(false)->addSystemTemplate($systemA);
        $unassigned = $this->template('Unassigned datasheet');
        $device = (new BuildInstance())->setSerialNumber('ASSIGN-DEVICE')->setSystemTemplate($systemA);
        $cable = (new BuildInstance())->setSerialNumber('ASSIGN-CABLE')->setTemplateProject($project);
        foreach ([$systemA, $systemB, $project, $first, $second, $draft, $inactive, $unassigned, $device, $cable] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $id = $first->getId();
        $deviceId = $device->getId();
        $crawler = $client->request('GET', '/en/production/datasheet-templates/'.$id.'/edit');
        $form = $crawler->selectButton('Save draft')->form();
        $form['datasheet_template[systemTemplates]']->select([(string) $systemA->getId(), (string) $systemB->getId()]);
        $form['datasheet_template[projects]']->select([(string) $project->getId()]);
        $client->submit($form);
        self::assertResponseRedirects('/en/production/datasheet-templates/'.$id);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-datasheet-system-assignments]', 'Assigned system B');
        self::assertSelectorTextContains('[data-datasheet-project-assignments]', 'Assigned cable build project');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $repo = self::getContainer()->get(DatasheetTemplateRepository::class);
        foreach ([$deviceId, $cable->getId()] as $instanceId) {
            self::assertSame(['First datasheet', 'Second datasheet'], array_map(static fn (DatasheetTemplate $t) => $t->getName(), $repo->findForInstance($em->find(BuildInstance::class, $instanceId))));
        }
        $crawler = $client->request('GET', '/en/production/build-instances/'.$deviceId);
        self::assertCount(2, $crawler->filter('#content a[href$="/prepare"]'));
        self::assertSelectorExists('a[href$="/datasheets/'.$id.'/prepare"]');
        $client->request('GET', '/en/production/build-instances/'.$deviceId.'/datasheets/'.$id.'/prepare');
        self::assertResponseIsSuccessful();

        // Removing only the system assignment leaves the project and the other template intact.
        $crawler = $client->request('GET', '/en/production/datasheet-templates/'.$id.'/edit');
        $form = $crawler->selectButton('Save draft')->form();
        $form['datasheet_template[systemTemplates]']->select([]);
        $client->submit($form);
        self::assertResponseRedirects();
        $client->request('GET', '/en/production/build-instances/'.$deviceId);
        self::assertSelectorNotExists('a[href$="/datasheets/'.$id.'/prepare"]');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(1, $em->find(DatasheetTemplate::class, $id)->getProjects());
        self::assertCount(1, $em->find(DatasheetTemplate::class, $second->getId())->getSystemTemplates());
    }

    public function testDirectUrlsCannotUseUnassignedInactiveOrDraftTemplates(): void
    {
        $client = $this->adminClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $system = (new SystemTemplate())->setName('Restricted datasheet system');
        $instance = (new BuildInstance())->setSerialNumber('ASSIGN-RESTRICT')->setSystemTemplate($system);
        $templates = [$this->template('Unassigned')->addSystemTemplate($system), $this->template('Inactive')->addSystemTemplate($system), $this->template('Draft', false)->addSystemTemplate($system)];
        foreach ([$system, $instance, ...$templates] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $client->catchExceptions(true);
        foreach ($templates as $template) {
            $url = '/en/production/build-instances/'.$instance->getId().'/datasheets/'.$template->getId();
            if ($template->getName() === 'Draft') {
                $client->request('GET', $url.'/prepare');
                self::assertResponseStatusCodeSame(404);
                continue;
            }
            $crawler = $client->request('GET', $url.'/prepare');
            self::assertResponseIsSuccessful();
            $token = $crawler->filter('#content form input[name="_token"]')->attr('value');
            $client->request('POST', $url.'/release', ['_token' => 'invalid']);
            self::assertResponseStatusCodeSame(403);
            $em = self::getContainer()->get(EntityManagerInterface::class);
            $saved = $em->find(DatasheetTemplate::class, $template->getId());
            if ($template->getName() === 'Unassigned') {
                $saved->getSystemTemplates()->clear();
            } else {
                $saved->setActive(false);
            }
            $em->flush();
            $client->request('GET', $url.'/prepare');
            self::assertResponseStatusCodeSame(404);
            foreach (['preview.pdf', 'release'] as $action) {
                $client->request('POST', $url.'/'.$action, ['_token' => $token]);
                self::assertResponseStatusCodeSame(404);
            }
        }
    }

    public function testInvalidOrUnauthorizedAssignmentCannotChangeExistingLinks(): void
    {
        $client = $this->adminClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $system = (new SystemTemplate())->setName('Protected assignment');
        $template = $this->template('Protected datasheet')->addSystemTemplate($system);
        $em->persist($system);
        $em->persist($template);
        $em->flush();
        $id = $template->getId();
        $url = '/en/production/datasheet-templates/'.$id.'/edit';
        $crawler = $client->request('GET', $url);
        $values = $crawler->selectButton('Save draft')->form()->getPhpValues();
        $values['datasheet_template']['systemTemplates'] = ['2147483647'];
        $client->request('POST', $url, $values);
        self::assertResponseStatusCodeSame(422);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertCount(1, $em->find(DatasheetTemplate::class, $id)->getSystemTemplates());
        $admin = $em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->getPermissions()->setPermissionValue('production_system_templates', 'read', false);
        $admin->getPermissions()->setPermissionValue('projects', 'read', false);
        $em->flush();
        $crawler = $client->request('GET', $url);
        self::assertSelectorNotExists('select[name="datasheet_template[systemTemplates][]"]');
        self::assertSelectorNotExists('select[name="datasheet_template[projects][]"]');
        $client->submit($crawler->selectButton('Save draft')->form());
        self::assertResponseRedirects();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(1, $em->find(DatasheetTemplate::class, $id)->getSystemTemplates());
        $reader = $em->getRepository(User::class)->findOneBy(['name' => 'user']);
        $client->loginUser($reader);
        $client->catchExceptions(true);
        $client->request('POST', $url, $values);
        self::assertResponseStatusCodeSame(403);
    }

    public function testProductionIndexLayoutAndButtonsAreConsistent(): void
    {
        $client = $this->adminClient();
        foreach (['customers', 'build-templates', 'order-import-mappings'] as $path) {
            $client->request('GET', '/en/production/'.$path);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('#content .card-header');
            self::assertSelectorExists('#content a.btn-success[href="/en/production/'.$path.'/new"]');
        }
        foreach (['datasheet-templates', 'protocol-templates'] as $path) {
            $client->request('GET', '/en/production/'.$path);
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('#content a[href="/en/production/build-instances"]');
        }
    }

    private function template(string $name, bool $published = true): DatasheetTemplate
    {
        $template = (new DatasheetTemplate())->setName($name)->setProductTitle($name);
        $revision = new DatasheetTemplateRevision();
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

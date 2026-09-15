<?php

declare(strict_types=1);

namespace App\Tests\Controller\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\ProtocolFieldType;
use App\Entity\Production\ProtocolTemplate;
use App\Entity\Production\ProtocolTemplateField;
use App\Entity\Production\ProtocolTemplateRevision;
use App\Entity\Production\ProtocolTemplateSection;
use App\Entity\UserSystem\User;
use App\Services\Production\ProtocolManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProtocolControllerTest extends WebTestCase
{
    public function testCompletionRequiresItsOwnPermissionAndCsrf(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        self::assertInstanceOf(User::class, $admin);
        $admin->setNeedPwChange(false);
        $template = (new ProtocolTemplate())->setName('Header permissions');
        $revision = new ProtocolTemplateRevision();
        $template->addRevision($revision);
        $revision->publish($admin);
        $instance = (new BuildInstance())->setSerialNumber('HEADER-PERMISSIONS');
        $system = (new \App\Entity\Production\SystemTemplate())->setName('Header permission system');
        $instance->setSystemTemplate($system);
        $template->addSystemTemplate($system);
        $em->persist($system);
        $em->persist($template);
        $em->persist($instance);
        $em->flush();
        $run = self::getContainer()->get(ProtocolManager::class)->createRun($instance, $revision, $admin);
        $runId = $run->getId();
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/en/production/protocol-runs/'.$runId.'/edit');
        $values = $crawler->selectButton('Complete')->form()->getPhpValues();
        $values['protocol_run']['_token'] = 'invalid';
        $client->request('POST', '/en/production/protocol-runs/'.$runId.'/edit', $values);
        self::assertResponseStatusCodeSame(422);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->getPermissions()->setPermissionValue('production_protocols', 'complete', false);
        $em->flush();
        $client->loginUser($admin);
        $crawler = $client->request('GET', '/en/production/protocol-runs/'.$runId.'/edit');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('button[value="complete"]');
        $values = $crawler->selectButton('Save draft')->form()->getPhpValues();
        $values['_action'] = 'complete';
        $client->request('POST', '/en/production/protocol-runs/'.$runId.'/edit', $values);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', '/en/production/protocol-runs/'.$runId.'/edit');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="protocol_run[protocol_date]"]');
    }

    public function testTemplateBuildInstanceAndDraftRunPagesRender(): void
    {
        $client = self::createClient();
        $client->catchExceptions(false);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $manager = self::getContainer()->get(ProtocolManager::class);
        $admin = $entityManager->getRepository(User::class)->findOneBy([
            'name' => 'admin',
        ]);
        self::assertInstanceOf(User::class, $admin);
        $admin->setNeedPwChange(false);
        $client->loginUser($admin);

        $template = (new ProtocolTemplate())->setName('HTTP-Test '.bin2hex(random_bytes(4)));
        $revision = (new ProtocolTemplateRevision())->setRevisionNumber(1);
        $template->addRevision($revision);
        $section = (new ProtocolTemplateSection())->setName('Messwerte');
        $revision->addSection($section);
        $section->addField((new ProtocolTemplateField())->setLabel('I0')->setType(ProtocolFieldType::Decimal)->setUnit('µA'));
        $section->addField((new ProtocolTemplateField())->setLabel('Grundfunktion')
            ->setType(ProtocolFieldType::TestResult)
            ->setPosition(1));
        $section->addField((new ProtocolTemplateField())->setLabel('Abschlussprüfung')
            ->setType(ProtocolFieldType::StaticNote)
            ->setHelpText('Nach der Montage ausfüllen.')
            ->setLayoutColumns(12)
            ->setStartNewRow(true)
            ->setPosition(2));
        $buildInstance = (new BuildInstance())->setSerialNumber('HTTP-'.bin2hex(random_bytes(6)));
        $system = (new \App\Entity\Production\SystemTemplate())->setName('HTTP measurement system');
        $buildInstance->setSystemTemplate($system);
        $template->addSystemTemplate($system);
        $entityManager->persist($system);
        $entityManager->persist($template);
        $entityManager->persist($buildInstance);
        $manager->publish($revision, $admin);
        $entityManager->flush();
        $run = $manager->createRun($buildInstance, $revision, $admin);
        $draft = $manager->getOrCreateDraft($template);
        $entityManager->persist($draft);
        $entityManager->flush();

        foreach ([
            '/en/production/protocol-templates',
            '/en/production/protocol-templates/'.$template->getId(),
            '/en/production/protocol-revisions/'.$draft->getId(),
            '/en/production/build-instances/'.$buildInstance->getId(),
            '/en/production/protocol-runs/'.$run->getId().'/edit',
        ] as $url) {
            $client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }

        $runId = $run->getId();
        $draftSection = $draft->getSections()
            ->first();
        self::assertNotNull($runId);
        self::assertInstanceOf(ProtocolTemplateSection::class, $draftSection);

        $crawler = $client->request('GET', '/en/production/protocol-sections/'.$draftSection->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[name$="[repeatable]"]'));
        self::assertCount(0, $crawler->filter('input[name$="[minRows]"]'));
        self::assertCount(0, $crawler->filter('input[name$="[maxRows]"]'));

        $crawler = $client->request('GET', '/en/production/protocol-sections/'.$draftSection->getId().'/fields/new');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name$="[required]"][checked]'));
        self::assertCount(1, $crawler->filter('select[data-controller="production--protocol-layout-width"]'));
        self::assertCount(4, $crawler->filter('select[data-controller="production--protocol-layout-width"] option'));

        $crawler = $client->request('GET', '/en/production/protocol-revisions/'.$draft->getId());
        $saveDraftForm = $crawler->filter('form[action$="/protocol-revisions/'.$draft->getId().'/save"]')->form();
        $client->submit($saveDraftForm);
        self::assertResponseRedirects('/en/production/protocol-templates/'.$template->getId());

        $crawler = $client->request('GET', '/en/production/protocol-runs/'.$runId.'/edit');
        self::assertCount(1, $crawler->filter('select[data-controller="production--protocol-test-result"]'));
        self::assertCount(3, $crawler->filter('select[data-controller="production--protocol-test-result"] option:not([value=""])'));
        self::assertSelectorTextContains('.border-start', 'Abschlussprüfung');
        self::assertSelectorTextContains('.border-start', 'Nach der Montage ausfüllen.');
        self::assertCount(1, $crawler->filter('.col-md-12 .border-start'));

        $form = $crawler->selectButton('Save draft')
            ->form();
        self::assertSelectorTextContains('[data-protocol-editor]', 'admin');
        self::assertSame('form-label small fw-semibold mb-1', $crawler->filter('label[for="protocol_run_protocol_date"]')->attr('class'));
        $form['protocol_run[protocol_date]'] = '2026-09-07';
        self::assertSame('', $form['protocol_run[notes]']->getValue());
        self::assertSelectorNotExists('textarea[name="protocol_run[notes]"][required]');
        self::assertCount(0, $crawler->filter('[data-protocol-notes]')->nextAll()->filter('section'));
        $form['protocol_run[notes]'] = 'Draft notes';
        $client->submit($form);
        self::assertResponseRedirects('/en/production/build-instances/'.$buildInstance->getId());

        $crawler = $client->request('GET', '/en/production/protocol-runs/'.$runId.'/edit');
        self::assertInputValueSame('protocol_run[protocol_date]', '2026-09-07');
        self::assertSelectorTextSame('textarea[name="protocol_run[notes]"]', 'Draft notes');
        $form = $crawler->selectButton('Complete')->form();
        $form['protocol_run[protocol_date]'] = '2026-09-08';
        $form['protocol_run[notes]'] = 'Unsaved notes on failed completion';
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertInputValueSame('protocol_run[protocol_date]', '2026-09-08');
        self::assertSelectorTextSame('textarea[name="protocol_run[notes]"]', 'Unsaved notes on failed completion');
        // Failed completion must not persist the new date or leave the draft status.
        $crawler = $client->request('GET', '/en/production/protocol-runs/'.$runId.'/edit');
        self::assertInputValueSame('protocol_run[protocol_date]', '2026-09-07');
        self::assertSelectorTextSame('textarea[name="protocol_run[notes]"]', 'Draft notes');
        $form = $crawler->selectButton('Complete')->form();
        $form['protocol_run[protocol_date]'] = '2026-09-09';
        $form['protocol_run[notes]'] = "Final notes\n<script>alert('not executable')</script>";
        foreach ($crawler->filter('[name^="protocol_run[answer_"]') as $input) {
            $form[$input->getAttribute('name')] = 'select' === $input->tagName ? 'pass' : '12.345';
        }
        $client->submit($form);
        self::assertResponseRedirects('/en/production/protocol-runs/'.$runId);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-protocol-date]', '2026-09-09');
        self::assertSelectorTextContains('[data-protocol-editor]', 'admin');
        self::assertSelectorTextContains('.card-body', '12.345');
        self::assertSelectorNotExists('input[name="protocol_run[protocol_date]"]');
        self::assertSelectorNotExists('textarea[name="protocol_run[notes]"]');
        self::assertSelectorTextContains('[data-protocol-notes]', "<script>alert('not executable')</script>");
        self::assertSelectorNotExists('[data-protocol-notes] script');

        $client->request('POST', '/en/production/protocol-runs/'.$runId.'/edit', [
            'protocol_run' => ['protocol_date' => '2020-01-01', 'notes' => 'Forged notes'],
        ]);
        self::assertResponseStatusCodeSame(409);
        $client->request('GET', '/en/production/protocol-runs/'.$runId);
        self::assertSelectorTextContains('[data-protocol-date]', '2026-09-09');
        self::assertSelectorTextContains('[data-protocol-notes]', 'Final notes');
    }
}

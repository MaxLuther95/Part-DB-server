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
        $client->submit($form);
        self::assertResponseRedirects('/en/production/build-instances/'.$buildInstance->getId());
    }
}

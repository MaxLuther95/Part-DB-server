<?php

declare(strict_types=1);

namespace App\Tests\Controller\Production;

use App\Entity\Production\ProtocolTemplate;
use App\Entity\UserSystem\User;
use App\Tests\Fixtures\ProtocolTemplateImportExample;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class ProtocolTemplateImportControllerTest extends WebTestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function testPreviewRequiresConfirmationAndValidCsrfAndCannotBeReplayed(): void
    {
        $client = $this->adminClient();
        $before = $this->countTemplates();
        $crawler = $this->upload($client, ProtocolTemplateImportExample::json());
        self::assertSame($before, $this->countTemplates());
        self::assertSelectorTextContains('.alert-info', 'NEW template with draft revision 1');
        $form = $crawler->selectButton('Import selected templates as new drafts')->form();
        $form['form[template_0][revision]'] = '7';
        $form['form[template_0][name]'] = 'HTTP imported template';
        $valid = $form->getPhpValues();
        $invalid = $valid;
        $invalid['form']['_token'] = 'invalid';
        $client->request('POST', '/en/production/protocol-templates/import/preview', $invalid);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->countTemplates());
        $client->request('POST', '/en/production/protocol-templates/import/preview', $valid);
        self::assertResponseRedirects();
        self::assertSame($before + 1, $this->countTemplates());
        $template = self::getContainer()->get(EntityManagerInterface::class)->getRepository(ProtocolTemplate::class)->findOneBy(['name' => 'HTTP imported template']);
        self::assertNotNull($template);
        self::assertSame(1, $template->getDraftRevision()->getRevisionNumber());
        self::assertNull($template->getPublishedRevision());
        $client->request('POST', '/en/production/protocol-templates/import/preview', $valid);
        self::assertResponseRedirects('/en/production/protocol-templates/import');
        self::assertSame($before + 1, $this->countTemplates());
    }

    public function testConflictKeepsPreviewChoicesAndCanBeResolvedByRenaming(): void
    {
        $client = $this->adminClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $existing = (new ProtocolTemplate())->setName('Existing HTTP template')->setDescription('Untouched');
        $em->persist($existing);
        $em->flush();
        $before = $this->countTemplates();
        $crawler = $this->upload($client, ProtocolTemplateImportExample::json());
        $form = $crawler->selectButton('Import selected templates as new drafts')->form();
        $form['form[template_0][revision]'] = '7';
        $form['form[template_0][name]'] = 'Existing HTTP template';
        $client->submit($form);
        self::assertResponseRedirects('/en/production/protocol-templates/import/preview');
        self::assertSame($before, $this->countTemplates());
        $crawler = $client->followRedirect();
        self::assertStringContainsString('Nothing was imported.', $client->getResponse()->getContent());
        $form = $crawler->selectButton('Import selected templates as new drafts')->form();
        self::assertSame('7', $form['form[template_0][revision]']->getValue());
        self::assertSame('Existing HTTP template', $form['form[template_0][name]']->getValue());
        $form['form[template_0][name]'] = 'Explicitly renamed HTTP template';
        $client->submit($form);
        self::assertResponseRedirects();
        self::assertSame($before + 1, $this->countTemplates());
    }

    public function testMalformedFilesAreRejectedAndStalePreviewCannotImportReplacement(): void
    {
        $client = $this->adminClient();
        $before = $this->countTemplates();
        $this->upload($client, '<?php echo "not JSON";', false);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($before, $this->countTemplates());
        $crawler = $this->upload($client, ProtocolTemplateImportExample::json());
        $form = $crawler->selectButton('Import selected templates as new drafts')->form();
        $form['form[template_0][revision]'] = '7';
        $form['form[template_0][name]'] = 'Must not import stale preview';
        $old = $form->getPhpValues();
        $crawler = $this->upload($client, ProtocolTemplateImportExample::json());
        $client->request('POST', '/en/production/protocol-templates/import/preview', $old);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->countTemplates());
        $cancel = $crawler->filter('#cancel-import')->form();
        $client->submit($cancel);
        self::assertResponseRedirects('/en/production/protocol-templates');
        $client->request('GET', '/en/production/protocol-templates/import/preview');
        self::assertResponseRedirects('/en/production/protocol-templates/import');
    }

    public function testReaderCannotUploadOrConfirmAndAdminNeedsCreatePermission(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $reader = $em->getRepository(User::class)->findOneBy(['name' => 'user']);
        $reader->setNeedPwChange(false);
        $client->loginUser($reader);
        foreach (['', '/preview', '/cancel'] as $suffix) {
            $client->request('POST', '/en/production/protocol-templates/import'.$suffix);
            self::assertResponseStatusCodeSame(403);
        }
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $em->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->setNeedPwChange(false);
        $admin->getPermissions()->setPermissionValue('production_protocol_templates', 'create', false);
        $em->flush();
        $client->loginUser($admin);
        $client->request('GET', '/en/production/protocol-templates/import');
        self::assertResponseStatusCodeSame(403);
    }

    private function adminClient(): KernelBrowser
    {
        $client = self::createClient();
        $admin = self::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->setNeedPwChange(false);
        $client->loginUser($admin);

        return $client;
    }

    private function countTemplates(): int
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(ProtocolTemplate::class)->count([]);
    }

    private function upload(KernelBrowser $client, string $json, bool $valid = true): Crawler
    {
        $path = tempnam(sys_get_temp_dir(), 'partdb-import-test-');
        $this->files[] = $path;
        file_put_contents($path, $json);
        $crawler = $client->request('GET', '/en/production/protocol-templates/import');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Check file and show preview')->form();
        $form['form[file]']->upload($path);
        $client->submit($form);
        if ($valid) {
            self::assertResponseRedirects('/en/production/protocol-templates/import/preview');

            return $client->followRedirect();
        }

        return $client->getCrawler();
    }
}

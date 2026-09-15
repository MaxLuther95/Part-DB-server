<?php

declare(strict_types=1);

namespace App\Tests\Controller\Production;

use App\Entity\Production\ProtocolRun;
use App\Entity\Production\ProtocolRunStatus;
use App\Services\Production\ProtocolManager;
use App\Tests\Fixtures\ProtocolRunScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class IncompleteProtocolControllerTest extends WebTestCase
{
    public function testExistingOptionalFieldRemainsOptional(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $run = ProtocolRunScenario::create($em, self::getContainer()->get(ProtocolManager::class), false);
        $id = $run->getId();
        $answerId = $run->getRows()->first()->getAnswers()->first()->getId();
        $client->loginUser($run->getStartedBy());
        $crawler = $client->request('GET', '/en/production/protocol-runs/'.$id.'/edit');
        $form = $crawler->selectButton('Complete')->form();
        $form['protocol_run[answer_'.$answerId.']'] = '';
        $client->submit($form);
        self::assertResponseRedirects('/en/production/protocol-runs/'.$id);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame([], $em->find(ProtocolRun::class, $id)->getCompletionWarnings());
    }

    public function testDraftMayBeIncompleteAndCompletionRequiresExplicitAcceptance(): void
    {
        $client = self::createClient();
        $client->catchExceptions(false);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $run = ProtocolRunScenario::create($em, self::getContainer()->get(ProtocolManager::class));
        $id = $run->getId();
        $answerId = $run->getRows()->first()->getAnswers()->first()->getId();
        $client->loginUser($run->getStartedBy());
        $url = '/en/production/protocol-runs/'.$id.'/edit';
        $crawler = $client->request('GET', $url);
        $draft = $crawler->selectButton('Save draft')->form();
        $draft['protocol_run[answer_'.$answerId.']'] = '';
        $draft['protocol_run[protocol_date]'] = '';
        $draft['protocol_run[notes]'] = 'Keep my draft notes';
        $client->submit($draft);
        self::assertResponseRedirects();

        $crawler = $client->request('GET', $url);
        self::assertInputValueSame('protocol_run[protocol_date]', '');
        $client->submit($crawler->selectButton('Complete')->form());
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-incomplete-warning]', 'Voltage');
        self::assertSelectorTextContains('[data-incomplete-warning]', 'Laufzetteldatum');
        self::assertSelectorTextContains('textarea', 'Keep my draft notes');
        self::assertSelectorNotExists('[name="_accept_incomplete"][checked]');

        $values = $client->getCrawler()->selectButton('Complete')->form()->getPhpValues();
        $client->request('POST', $url, $values);
        self::assertResponseStatusCodeSame(422, 'A displayed warning without acceptance must not complete.');
        $values['_accept_incomplete'] = '1';
        $token = $values['_incomplete_token'];
        $values['_incomplete_token'] = 'forged';
        $client->request('POST', $url, $values);
        self::assertResponseStatusCodeSame(422);
        $values['_incomplete_token'] = $token;
        $client->request('POST', $url, $values);
        self::assertResponseRedirects('/en/production/protocol-runs/'.$id);
        $client->followRedirect();
        self::assertSelectorTextContains('[data-accepted-incomplete]', 'Voltage');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $stored = $em->find(ProtocolRun::class, $id);
        self::assertSame(ProtocolRunStatus::Completed, $stored->getStatus());
        self::assertNull($stored->getProtocolDate());
        self::assertNull($stored->getRows()->first()->getAnswers()->first()->getValue());
        self::assertCount(2, $stored->getCompletionWarnings());
        self::assertNotNull($stored->getCompletedBy());
    }

    public function testConfirmationDoesNotAuthorizeChangedOrInvalidValues(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $run = ProtocolRunScenario::create($em, self::getContainer()->get(ProtocolManager::class));
        $id = $run->getId();
        $answerId = $run->getRows()->first()->getAnswers()->first()->getId();
        $client->loginUser($run->getStartedBy());
        $url = '/en/production/protocol-runs/'.$id.'/edit';
        $crawler = $client->request('GET', $url);
        $form = $crawler->selectButton('Complete')->form();
        $form['protocol_run[answer_'.$answerId.']'] = '';
        $client->submit($form);
        self::assertResponseStatusCodeSame(422);
        $values = $client->getCrawler()->selectButton('Complete')->form()->getPhpValues();
        $values['_accept_incomplete'] = '1';
        $values['protocol_run']['notes'] = 'Changed after warning';
        $client->request('POST', $url, $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorNotExists('[name="_accept_incomplete"][checked]');
        self::assertSelectorTextContains('textarea', 'Changed after warning');

        $values = $client->getCrawler()->selectButton('Complete')->form()->getPhpValues();
        $values['_accept_incomplete'] = '1';
        $values['protocol_run']['answer_'.$answerId] = 'not a decimal';
        $client->request('POST', $url, $values);
        self::assertResponseStatusCodeSame(422);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(ProtocolRunStatus::Draft, $em->find(ProtocolRun::class, $id)->getStatus());
        self::assertSame('1.000', $em->find(ProtocolRun::class, $id)->getRows()->first()->getAnswers()->first()->getValue());
    }
}

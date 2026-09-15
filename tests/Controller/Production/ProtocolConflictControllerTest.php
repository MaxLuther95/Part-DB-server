<?php

declare(strict_types=1);

namespace App\Tests\Controller\Production;

use App\Entity\Production\ProtocolRun;
use App\Entity\Production\ProtocolRunStatus;
use App\Services\Production\ProtocolManager;
use App\Tests\Fixtures\ProtocolRunScenario;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProtocolConflictControllerTest extends WebTestCase
{
    public static function staleSubmissions(): iterable
    {
        yield 'old draft save' => [false, false, 'old'];
        yield 'old completion' => [false, true, 'old'];
        yield 'save after completion' => [true, false, 'old'];
        yield 'missing version' => [false, false, 'missing'];
        yield 'forged future version' => [false, false, 'future'];
    }

    #[DataProvider('staleSubmissions')]
    public function testStaleFormsKeepSubmittedValuesWithoutOverwriting(bool $firstCompletes, bool $secondCompletes, string $version): void
    {
        $client = self::createClient();
        $client->catchExceptions(false);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $run = ProtocolRunScenario::create($em, self::getContainer()->get(ProtocolManager::class));
        $id = $run->getId();
        $answerId = $run->getRows()->first()->getAnswers()->first()->getId();
        $url = '/en/production/protocol-runs/'.$id.'/edit';
        $client->loginUser($run->getStartedBy());
        $crawler = $client->request('GET', $url);
        $first = $crawler->selectButton('Save draft')->form()->getPhpValues();
        $stale = $first;
        $first['protocol_run']['answer_'.$answerId] = '1.111';
        $first['protocol_run']['notes'] = 'Saved by editor A';
        if ($firstCompletes) {
            $first['_action'] = 'complete';
        }
        $client->request('POST', $url, $first);
        self::assertResponseRedirects();

        $stale['protocol_run']['answer_'.$answerId] = '2.222';
        $stale['protocol_run']['notes'] = '<script>unsaved editor B</script>';
        $stale['protocol_run']['protocol_date'] = '2026-09-01';
        if ($secondCompletes) {
            $stale['_action'] = 'complete';
        }
        if ('missing' === $version) {
            unset($stale['protocol_run']['edit_version']);
        } elseif ('future' === $version) {
            $stale['protocol_run']['edit_version'] = '999999';
        }
        $client->request('POST', $url, $stale);
        self::assertResponseStatusCodeSame(409);
        self::assertSelectorExists('[data-protocol-conflict]');
        self::assertInputValueSame('protocol_run[answer_'.$answerId.']', '2.222');
        self::assertInputValueSame('protocol_run[protocol_date]', '2026-09-01');
        self::assertSelectorTextContains('textarea', '<script>unsaved editor B</script>');
        self::assertSelectorNotExists('[data-submitted-protocol] script');
        self::assertSelectorNotExists('[data-submitted-protocol] button');
        self::assertSelectorNotExists('input[name="protocol_run[edit_version]"]');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $stored = $em->find(ProtocolRun::class, $id);
        self::assertSame('1.111', $stored->getRows()->first()->getAnswers()->first()->getValue());
        self::assertSame('Saved by editor A', $stored->getNotes());
        self::assertSame($firstCompletes ? ProtocolRunStatus::Completed : ProtocolRunStatus::Draft, $stored->getStatus());
    }

    public function testOldInvalidationPreservesFirstReason(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $run = ProtocolRunScenario::create($em, self::getContainer()->get(ProtocolManager::class));
        $run->complete($run->getStartedBy());
        $em->flush();
        $id = $run->getId();
        $client->loginUser($run->getStartedBy());
        $crawler = $client->request('GET', '/en/production/protocol-runs/'.$id);
        $form = $crawler->filter('form[action$="/invalidate"]')->form();
        $first = $form->getPhpValues();
        $first['reason'] = 'First reason';
        $client->request('POST', $form->getUri(), $first);
        self::assertResponseRedirects();
        $first['reason'] = 'Unsaved second reason';
        $client->request('POST', $form->getUri(), $first);
        self::assertResponseStatusCodeSame(409);
        self::assertSelectorTextContains('[data-submitted-reason]', 'Unsaved second reason');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame('First reason', $em->find(ProtocolRun::class, $id)->getInvalidReason());
    }

    public function testConflictDuringFlushRendersSubmittedValuesAfterRollback(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $client->catchExceptions(false);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $run = ProtocolRunScenario::create($em, self::getContainer()->get(ProtocolManager::class));
        $id = $run->getId();
        $answerId = $run->getRows()->first()->getAnswers()->first()->getId();
        $client->loginUser($run->getStartedBy());
        $crawler = $client->request('GET', '/en/production/protocol-runs/'.$id.'/edit');
        $form = $crawler->selectButton('Save draft')->form();
        $form['protocol_run[answer_'.$answerId.']'] = '8.888';
        $form['protocol_run[notes]'] = 'Keep this after rollback';
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $listener = new class($id) {
            public bool $fired = false;

            public function __construct(private readonly int $id) {}

            public function onFlush(\Doctrine\ORM\Event\OnFlushEventArgs $event): void
            {
                $em = $event->getObjectManager();
                foreach ($em->getUnitOfWork()->getScheduledEntityUpdates() as $entity) {
                    if (! $this->fired && $entity instanceof ProtocolRun && $entity->getId() === $this->id) {
                        $this->fired = true;
                        $em->getConnection()->executeStatement('UPDATE production_protocol_runs SET version = version + 1 WHERE id = ?', [$this->id]);
                    }
                }
            }
        };
        $em->getEventManager()->addEventListener(['onFlush'], $listener);
        try {
            $client->submit($form);
            self::assertTrue($listener->fired);
            self::assertResponseStatusCodeSame(409);
            self::assertInputValueSame('protocol_run[answer_'.$answerId.']', '8.888');
            self::assertSelectorTextContains('textarea', 'Keep this after rollback');
            self::assertSame('1.000', $em->getConnection()->fetchOne('SELECT decimal_value FROM production_protocol_answers WHERE id = ?', [$answerId]));
        } finally {
            $em->getEventManager()->removeEventListener(['onFlush'], $listener);
        }
    }
}

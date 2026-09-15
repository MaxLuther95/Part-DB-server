<?php

declare(strict_types=1);

namespace App\Tests\Services\Production;

use App\Entity\Production\ProtocolRun;
use App\Services\Production\ProtocolManager;
use App\Tests\Fixtures\ProtocolRunScenario;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProtocolConcurrencyTest extends KernelTestCase
{
    public static function mutations(): iterable
    {
        yield 'answer without controller touch' => ['answer'];
        yield 'clear answer' => ['clear'];
        yield 'same editor' => ['touch'];
        yield 'complete' => ['complete'];
        yield 'notes' => ['notes'];
        yield 'invalidate' => ['invalidate'];
    }

    #[DataProvider('mutations')]
    public function testStaleMutationRollsBack(string $mutation): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $run = ProtocolRunScenario::create($em, self::getContainer()->get(ProtocolManager::class));
        if ('invalidate' === $mutation) {
            $run->complete($run->getStartedBy());
            $em->flush();
        }
        $connection = $em->getConnection();
        $answer = $run->getRows()->first()->getAnswers()->first();
        // Simulate a committed competing write after this request loaded its snapshot.
        // A separate two-connection integration check covers actual connection isolation.
        $connection->executeStatement('UPDATE production_protocol_runs SET version = version + 1 WHERE id = ?', [$run->getId()]);
        match ($mutation) {
            'answer' => $answer->setValue('9.999'),
            'clear' => $answer->clearValue(),
            'touch' => $run->touch($run->getStartedBy()),
            'complete' => $run->complete($run->getStartedBy()),
            'notes' => $run->setNotes('Stale note'),
            'invalidate' => $run->invalidate('Stale reason', $run->getStartedBy()),
        };
        try {
            $em->flush();
            self::fail('A stale protocol write must fail atomically.');
        } catch (OptimisticLockException) {
            self::assertSame('1.000', $connection->fetchOne('SELECT decimal_value FROM production_protocol_answers WHERE id = ?', [$answer->getId()]));
            self::assertNull($connection->fetchOne('SELECT notes FROM production_protocol_runs WHERE id = ?', [$run->getId()]));
        }
    }

    public function testAnswerOnlySavesAdvanceTheRunVersion(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $run = ProtocolRunScenario::create($em, self::getContainer()->get(ProtocolManager::class));
        $version = $run->getVersion();
        $answer = $run->getRows()->first()->getAnswers()->first();
        foreach (['2.000', '3.000', '4.000'] as $value) {
            $answer->setValue($value);
            $em->flush();
            self::assertSame(++$version, $run->getVersion());
        }
    }
}

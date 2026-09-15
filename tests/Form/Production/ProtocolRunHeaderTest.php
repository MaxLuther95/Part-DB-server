<?php

declare(strict_types=1);

namespace App\Tests\Form\Production;

use App\Entity\Production\ProtocolRun;
use App\Entity\UserSystem\User;
use App\Form\Production\ProtocolRunType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

final class ProtocolRunHeaderTest extends KernelTestCase
{
    public function testDateRemainsTheSameCalendarDayAcrossTimezones(): void
    {
        self::bootKernel();
        $timezone = date_default_timezone_get();
        try {
            $run = (new ProtocolRun())->setProtocolDate(new \DateTimeImmutable('2026-09-10', new \DateTimeZone('Pacific/Kiritimati')));
            date_default_timezone_set('America/Los_Angeles');
            $form = self::getContainer()->get(FormFactoryInterface::class)->create(ProtocolRunType::class, null, [
                'protocol_run' => $run, 'csrf_protection' => false,
            ]);
            self::assertSame('2026-09-10', $form->get('protocol_date')->getViewData());
            $form->submit(['protocol_date' => '2026-09-09', 'edit_version' => (string) $run->getVersion()]);
            self::assertTrue($form->isValid(), (string) $form->getErrors(true));
            self::assertSame('2026-09-09', $run->getProtocolDate()?->format('Y-m-d'));
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public function testInvalidDatesAndForgedEditorAreRejected(): void
    {
        self::bootKernel();
        foreach ([
            ['protocol_date' => '2026-02-30'],
            ['protocol_date' => 'not-a-date'],
            ['protocol_date' => '2026-09-10', 'last_edited_by_name' => 'forged'],
            ['protocol_date' => '2026-09-10', 'notes' => str_repeat('x', 10001)],
        ] as $payload) {
            $run = (new ProtocolRun())->setStartedBy((new User())->setName('actual'));
            $form = self::getContainer()->get(FormFactoryInterface::class)->create(ProtocolRunType::class, null, [
                'protocol_run' => $run, 'csrf_protection' => false,
            ]);
            $form->submit($payload + ['edit_version' => (string) $run->getVersion()]);
            self::assertFalse($form->isValid());
            self::assertSame('actual', $run->getLastEditedByName());
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber\UserSystem;

use App\Entity\Parts\Part;
use App\Entity\UserSystem\PermissionData;
use App\Entity\UserSystem\User;
use App\EventSubscriber\UserSystem\UpgradePermissionsSchemaSubscriber;
use App\Services\UserSystem\PermissionSchemaUpdater;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class UpgradePermissionsPersistenceTest extends KernelTestCase
{
    public function testSchemaUpgradePreservesDenialsAndDoesNotFlushPendingEntities(): void
    {
        self::bootKernel();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $manager->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->getPermissions()->setSchemaVersion(4);
        $admin->getPermissions()->setPermissionValue('production_orders', 'read', false);
        $manager->flush();

        $pending = new Part();
        $pending->setName('Pending synthetic API entity');
        $manager->persist($pending);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($admin);
        $subscriber = new UpgradePermissionsSchemaSubscriber($security, self::getContainer()->get(PermissionSchemaUpdater::class), $manager);
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $subscriber->onRequest(new RequestEvent(self::$kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        self::assertNull($pending->getId(), 'A permission update must not flush an unrelated pending API entity.');
        $persisted = json_decode($manager->getConnection()->fetchOne('SELECT permissions_data FROM users WHERE id = ?', [$admin->getId()]), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(PermissionData::CURRENT_SCHEMA_VERSION, $persisted['$ver']);
        self::assertFalse($persisted['production_orders']['read']);
    }
}

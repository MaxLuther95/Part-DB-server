<?php

declare(strict_types=1);

namespace App\Tests\API\Endpoints;

use App\Entity\UserSystem\User;
use App\Tests\API\AuthenticatedApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class LabelTargetAuthorizationTest extends AuthenticatedApiTestCase
{
    public function testLabelPermissionDoesNotBypassTargetReadPermission(): void
    {
        $client = self::createAuthenticatedClient();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $admin = $manager->getRepository(User::class)->findOneBy(['name' => 'admin']);
        $admin->getPermissions()->setPermissionValue('parts', 'read', false);
        $manager->flush();

        $client->request('POST', '/api/labels/generate', ['json' => [
            'profileId' => 1,
            'elementIds' => '1',
        ]]);

        self::assertResponseStatusCodeSame(403);
    }
}

<?php

declare(strict_types=1);

/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2023 Jan Böhmer (https://github.com/jbtronics)
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as published
 *  by the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace App\EventSubscriber\UserSystem;

use App\Entity\UserSystem\Group;
use App\Entity\UserSystem\User;
use App\Services\UserSystem\PermissionSchemaUpdater;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The purpose of this event subscriber is to check if the permission schema of the current user is up-to-date and upgrade it automatically if needed.
 */
readonly class UpgradePermissionsSchemaSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Security $security,
        private PermissionSchemaUpdater $permissionSchemaUpdater,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        if (! $event->isMainRequest()) {
            return;
        }
        $user = $this->security->getUser();
        if (! $user instanceof UserInterface) {
            // Retrieve anonymous user
            $user = $this->entityManager->getRepository(User::class)->getAnonymousUser();
        }

        /** @var Session $session */
        $session = $event->getRequest()
            ->getSession();
        $flashBag = $session->getFlashBag();

        // Check if the user is an instance of User, otherwise we can't upgrade the schema
        if (! $user instanceof User) {
            return;
        }

        if ($this->permissionSchemaUpdater->isSchemaUpdateNeeded($user)) {
            $this->permissionSchemaUpdater->userUpgradeSchemaRecursively($user);
            $this->persistUpgradedPermissions($user);
            $flashBag->add('notice', 'user.permissions_schema_updated');
        }
    }

    private function persistUpgradedPermissions(User $user): void
    {
        if (null === $user->getId()) {
            return;
        }

        // A global EntityManager::flush() is unsafe during kernel.request:
        // API Platform may already have deserialized new related entities but
        // has not handed them to its persistence processor yet. Persist only
        // the upgraded JSON columns on the already existing user/group rows.
        $this->entityManager->getConnection()
            ->transactional(function (Connection $connection) use ($user): void {
                $connection->update($connection->quoteIdentifier('users'), [
                    'permissions_data' => $user->getPermissions()
                        ->toPersistenceArray(),
                ], [
                    'id' => $user->getId(),
                ], [
                    'permissions_data' => Types::JSON,
                    'id' => Types::INTEGER,
                ]);

                $group = $user->getGroup();
                while ($group instanceof Group && null !== $group->getId()) {
                    $connection->update($connection->quoteIdentifier('groups'), [
                        'permissions_data' => $group->getPermissions()
                            ->toPersistenceArray(),
                    ], [
                        'id' => $group->getId(),
                    ], [
                        'permissions_data' => Types::JSON,
                        'id' => Types::INTEGER,
                    ]);
                    $parent = $group->getParent();
                    $group = $parent instanceof Group ? $parent : null;
                }
            });
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onRequest',
        ];
    }
}

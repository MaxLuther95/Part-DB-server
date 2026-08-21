<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\CustomerProject;
use App\Entity\Production\ProductionHistory;
use App\Entity\UserSystem\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

final class ProductionHistoryRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    public function record(
        CustomerProject $project,
        string $eventType,
        string $description = '',
        ?BuildInstance $buildInstance = null,
    ): void {
        $user = $this->security->getUser();
        $actor = $user instanceof User ? $user : null;

        $this->entityManager->persist(new ProductionHistory(
            $project,
            $eventType,
            $description,
            $buildInstance,
            $actor,
        ));
    }
}

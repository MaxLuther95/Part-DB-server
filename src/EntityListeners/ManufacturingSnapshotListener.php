<?php

declare(strict_types=1);

namespace App\EntityListeners;

use App\Entity\Production\ProjectPosition;
use App\Services\Production\ManufacturingSnapshotFactory;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::preFlush)]
final readonly class ManufacturingSnapshotListener
{
    public function __construct(private ManufacturingSnapshotFactory $snapshots) {}

    public function preFlush(PreFlushEventArgs $event): void
    {
        $uow = $event->getObjectManager()->getUnitOfWork();
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof ProjectPosition) {
                $this->snapshots->initialize($entity);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Repository\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\BuildStatus;
use App\Entity\Production\ProjectPosition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<BuildInstance> */
final class BuildInstanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BuildInstance::class);
    }

    /** @return list<BuildInstance> */
    public function findAssignableTo(ProjectPosition $position): array
    {
        $queryBuilder = $this->createQueryBuilder('buildInstance')
            ->where('buildInstance.projectPosition IS NULL')
            ->andWhere('buildInstance.customerProject IS NULL')
            ->andWhere('buildInstance.status != :scrapped')
            ->setParameter('scrapped', BuildStatus::Scrapped->value)
            ->orderBy('buildInstance.serialNumber', 'ASC');

        if (null !== $position->getSystemTemplate()) {
            $queryBuilder
                ->andWhere('buildInstance.systemTemplate = :systemTemplate')
                ->setParameter('systemTemplate', $position->getSystemTemplate());
        } elseif (null !== $position->getTemplateProject()) {
            $queryBuilder
                ->andWhere('buildInstance.templateProject = :templateProject')
                ->setParameter('templateProject', $position->getTemplateProject());
        } else {
            return [];
        }

        return $queryBuilder->getQuery()->getResult();
    }
}

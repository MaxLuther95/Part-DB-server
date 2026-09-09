<?php

declare(strict_types=1);

namespace App\Repository\Production;

use App\Entity\Production\DatasheetTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DatasheetTemplate>
 */
final class DatasheetTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DatasheetTemplate::class);
    }

    /**
     * @return list<DatasheetTemplate>
     */
    public function findActiveWithPublishedRevision(): array
    {
        return $this->createQueryBuilder('template')
            ->select('DISTINCT template')
            ->innerJoin('template.revisions', 'revision')
            ->andWhere('template.active = true')
            ->andWhere('revision.status = :status')
            ->setParameter('status', 'published')
            ->orderBy('template.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

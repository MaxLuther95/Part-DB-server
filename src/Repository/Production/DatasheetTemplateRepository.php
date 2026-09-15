<?php

declare(strict_types=1);

namespace App\Repository\Production;

use App\Entity\Production\DatasheetTemplate;
use App\Entity\Production\BuildInstance;
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

    /** @return list<DatasheetTemplate> */
    public function findForInstance(BuildInstance $instance): array
    {
        $system = $instance->getSystemTemplate();
        $target = $system ?? $instance->getTemplateProject();
        if (null === $target) {
            return [];
        }

        return $this->createQueryBuilder('template')
            ->select('DISTINCT template')
            ->innerJoin(null !== $system ? 'template.systemTemplates' : 'template.projects', 'target')
            ->innerJoin('template.revisions', 'revision')
            ->where('target.id = :target')->setParameter('target', $target->getId())
            ->andWhere('template.active = true')
            ->andWhere('revision.status = :status')->setParameter('status', 'published')
            ->orderBy('template.name', 'ASC')->addOrderBy('template.id', 'ASC')
            ->getQuery()->getResult();
    }

}

<?php

declare(strict_types=1);

namespace App\Repository\Production;

use App\Entity\Production\ProtocolTemplate;
use App\Entity\Production\BuildInstance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProtocolTemplate>
 */
final class ProtocolTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProtocolTemplate::class);
    }

    /**
     * @return list<ProtocolTemplate>
     */
    public function findActiveWithPublishedRevision(): array
    {
        return $this->createQueryBuilder('template')
            ->select('DISTINCT template')
            ->innerJoin('template.revisions', 'revision')
            ->leftJoin('template.systemTemplates', 'systems')->addSelect('systems')
            ->andWhere('template.active = true')
            ->andWhere('revision.status = :status')
            ->setParameter('status', 'published')
            ->orderBy('template.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findForInstance(BuildInstance $instance): ?ProtocolTemplate
    {
        foreach ($this->findActiveWithPublishedRevision() as $template) {
            if ($template->appliesTo($instance)) {
                return $template;
            }
        }

        return null;
    }
}

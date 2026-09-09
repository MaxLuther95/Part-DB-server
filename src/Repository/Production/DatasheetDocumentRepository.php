<?php

declare(strict_types=1);

namespace App\Repository\Production;

use App\Entity\Production\BuildInstance;
use App\Entity\Production\DatasheetDocument;
use App\Entity\Production\DatasheetTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DatasheetDocument>
 */
final class DatasheetDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DatasheetDocument::class);
    }

    public function getNextDocumentRevision(BuildInstance $instance, DatasheetTemplate $template): int
    {
        $highest = $this->createQueryBuilder('document')
            ->select('MAX(document.documentRevision)')
            ->andWhere('document.buildInstance = :instance')
            ->andWhere('document.template = :template')
            ->setParameter('instance', $instance)
            ->setParameter('template', $template)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $highest + 1;
    }
}

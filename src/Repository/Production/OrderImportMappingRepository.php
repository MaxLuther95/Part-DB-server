<?php
declare(strict_types=1);
namespace App\Repository\Production;
use App\Entity\Production\OrderImportMapping;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
/** @extends ServiceEntityRepository<OrderImportMapping> */
final class OrderImportMappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, OrderImportMapping::class); }
    public function findActiveForDescription(string $description): ?OrderImportMapping
    {
        return $this->findOneBy(['normalizedDescription' => OrderImportMapping::normalize($description), 'active' => true]);
    }
}

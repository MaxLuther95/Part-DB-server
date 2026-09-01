<?php
declare(strict_types=1);
namespace App\Repository\Production;
use App\Entity\Production\OrderImportLine;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
/** @extends ServiceEntityRepository<OrderImportLine> */
final class OrderImportLineRepository extends ServiceEntityRepository { public function __construct(ManagerRegistry $registry) { parent::__construct($registry, OrderImportLine::class); } }

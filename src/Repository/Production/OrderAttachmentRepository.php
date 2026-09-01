<?php
declare(strict_types=1);
namespace App\Repository\Production;
use App\Entity\Production\OrderAttachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
/** @extends ServiceEntityRepository<OrderAttachment> */
final class OrderAttachmentRepository extends ServiceEntityRepository { public function __construct(ManagerRegistry $registry) { parent::__construct($registry, OrderAttachment::class); } }

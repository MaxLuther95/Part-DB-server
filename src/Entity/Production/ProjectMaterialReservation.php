<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\Parts\Part;
use App\Entity\Parts\PartLot;
use App\Entity\Parts\StorageLocation;
use App\Entity\UserSystem\User;
use App\Repository\Production\ProjectMaterialReservationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProjectMaterialReservationRepository::class)]
#[ORM\Table(name: 'production_project_material_reservations')]
#[ORM\Index(name: 'IDX_PROD_RES_PROJECT', columns: ['customer_project_id'])]
#[ORM\Index(name: 'IDX_PROD_RES_PART', columns: ['part_id'])]
#[ORM\Index(name: 'IDX_PROD_RES_LOT', columns: ['source_part_lot_id'])]
#[ORM\Index(name: 'IDX_PROD_RES_SITE', columns: ['site_id'])]
#[ORM\Index(name: 'IDX_PROD_RES_USER', columns: ['reserved_by_id'])]
final class ProjectMaterialReservation extends AbstractProductionEntity
{
    #[ORM\ManyToOne(targetEntity: CustomerProject::class, inversedBy: 'materialReservations')]
    #[ORM\JoinColumn(name: 'customer_project_id', nullable: false, onDelete: 'CASCADE')]
    private ?CustomerProject $customerProject = null;

    #[ORM\ManyToOne(targetEntity: Part::class)]
    #[ORM\JoinColumn(name: 'part_id', nullable: true, onDelete: 'SET NULL')]
    private ?Part $part = null;

    #[ORM\Column(name: 'part_name', type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    private string $partName = '';

    #[ORM\Column(name: 'part_reference_id', type: Types::INTEGER, nullable: true)]
    private ?int $partReferenceId = null;

    #[ORM\ManyToOne(targetEntity: PartLot::class)]
    #[ORM\JoinColumn(name: 'source_part_lot_id', nullable: true, onDelete: 'SET NULL')]
    private ?PartLot $sourcePartLot = null;

    #[ORM\Column(name: 'source_lot_name', type: Types::STRING, length: 255, nullable: true)]
    private ?string $sourceLotName = null;

    #[ORM\ManyToOne(targetEntity: StorageLocation::class)]
    #[ORM\JoinColumn(name: 'site_id', nullable: true, onDelete: 'SET NULL')]
    private ?StorageLocation $site = null;

    #[ORM\Column(name: 'site_name', type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    private string $siteName = '';

    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\Positive]
    private int $quantity = 0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reserved_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $reservedBy = null;

    public function getCustomerProject(): ?CustomerProject { return $this->customerProject; }
    public function setCustomerProject(CustomerProject $project): self { $this->customerProject = $project; if (!$project->getMaterialReservations()->contains($this)) { $project->getMaterialReservations()->add($this); } return $this; }
    public function getPart(): ?Part { return $this->part; }
    public function setPart(?Part $part): self { $this->part = $part; if (null !== $part) { $this->partName = $part->getName(); $this->partReferenceId = $part->getId(); } return $this; }
    public function getPartName(): string { return $this->part?->getName() ?? $this->partName; }
    public function getPartReferenceId(): ?int { return $this->part?->getId() ?? $this->partReferenceId; }
    public function getSourcePartLot(): ?PartLot { return $this->sourcePartLot; }
    public function setSourcePartLot(?PartLot $lot): self { $this->sourcePartLot = $lot; if (null !== $lot) { $this->sourceLotName = $lot->getName(); } return $this; }
    public function getSourceLotName(): ?string { return $this->sourcePartLot?->getName() ?? $this->sourceLotName; }
    public function getSite(): ?StorageLocation { return $this->site; }
    public function setSite(StorageLocation $site): self { $this->site = $site; $this->siteName = $site->getFullPath(); return $this; }
    public function getSiteName(): string { return $this->site?->getFullPath() ?? $this->siteName; }
    public function getQuantity(): int { return $this->quantity; }
    public function setQuantity(int $quantity): self { $this->quantity = $quantity; return $this; }
    public function getReservedBy(): ?User { return $this->reservedBy; }
    public function setReservedBy(?User $user): self { $this->reservedBy = $user; return $this; }
}

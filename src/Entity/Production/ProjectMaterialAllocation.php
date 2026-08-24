<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\Parts\Part;
use App\Entity\Parts\PartLot;
use App\Entity\UserSystem\User;
use App\Repository\Production\ProjectMaterialAllocationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProjectMaterialAllocationRepository::class)]
#[ORM\Table(name: 'production_project_material_allocations')]
#[ORM\Index(name: 'IDX_PROD_MATERIAL_PROJECT', columns: ['customer_project_id'])]
#[ORM\Index(name: 'IDX_PROD_MATERIAL_PART', columns: ['part_id'])]
#[ORM\Index(name: 'IDX_PROD_MATERIAL_LOT', columns: ['source_part_lot_id'])]
#[ORM\Index(name: 'IDX_PROD_MATERIAL_USER', columns: ['allocated_by_id'])]
class ProjectMaterialAllocation extends AbstractProductionEntity
{
    #[ORM\ManyToOne(targetEntity: CustomerProject::class, inversedBy: 'materialAllocations')]
    #[ORM\JoinColumn(name: 'customer_project_id', nullable: false, onDelete: 'CASCADE')]
    private ?CustomerProject $customerProject = null;

    #[ORM\ManyToOne(targetEntity: Part::class)]
    #[ORM\JoinColumn(name: 'part_id', nullable: true, onDelete: 'SET NULL')]
    private ?Part $part = null;

    #[ORM\Column(name: 'part_name', type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $partName = '';

    #[ORM\Column(name: 'part_reference_id', type: Types::INTEGER, nullable: true)]
    private ?int $partReferenceId = null;

    #[ORM\ManyToOne(targetEntity: PartLot::class)]
    #[ORM\JoinColumn(name: 'source_part_lot_id', nullable: true, onDelete: 'SET NULL')]
    private ?PartLot $sourcePartLot = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\Positive]
    private int $quantity = 0;

    #[ORM\Column(name: 'serial_number', type: Types::STRING, length: 128, nullable: true)]
    #[Assert\Length(max: 128)]
    private ?string $serialNumber = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'allocated_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $allocatedBy = null;

    public function getCustomerProject(): ?CustomerProject
    {
        return $this->customerProject;
    }

    public function setCustomerProject(?CustomerProject $customerProject): self
    {
        $this->customerProject = $customerProject;

        return $this;
    }

    public function getPart(): ?Part
    {
        return $this->part;
    }

    public function setPart(?Part $part): self
    {
        $this->part = $part;
        if (null !== $part) {
            $this->partName = $part->getName();
            $this->partReferenceId = $part->getId();
        }

        return $this;
    }

    public function getPartName(): string
    {
        return $this->part?->getName() ?? $this->partName;
    }

    public function getPartReferenceId(): ?int
    {
        return $this->part?->getId() ?? $this->partReferenceId;
    }

    public function getSourcePartLot(): ?PartLot
    {
        return $this->sourcePartLot;
    }

    public function setSourcePartLot(?PartLot $sourcePartLot): self
    {
        $this->sourcePartLot = $sourcePartLot;

        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): self
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getSerialNumber(): ?string
    {
        return $this->serialNumber;
    }

    public function setSerialNumber(?string $serialNumber): self
    {
        $serialNumber = null === $serialNumber ? null : trim($serialNumber);
        $this->serialNumber = '' === $serialNumber ? null : $serialNumber;

        return $this;
    }

    public function getAllocatedBy(): ?User
    {
        return $this->allocatedBy;
    }

    public function setAllocatedBy(?User $allocatedBy): self
    {
        $this->allocatedBy = $allocatedBy;

        return $this;
    }
}

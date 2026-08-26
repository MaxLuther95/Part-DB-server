<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\Parts\Part;
use App\Entity\Parts\PartLot;
use App\Entity\UserSystem\User;
use App\Repository\Production\BuildMaterialUsageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BuildMaterialUsageRepository::class)]
#[ORM\Table(name: 'production_build_material_usages')]
#[ORM\Index(name: 'IDX_PROD_USAGE_BUILD', columns: ['build_instance_id'])]
#[ORM\Index(name: 'IDX_PROD_USAGE_PART', columns: ['part_id'])]
#[ORM\Index(name: 'IDX_PROD_USAGE_LOT', columns: ['source_part_lot_id'])]
#[ORM\Index(name: 'IDX_PROD_USAGE_USER', columns: ['allocated_by_id'])]
final class BuildMaterialUsage extends AbstractProductionEntity
{
    #[ORM\ManyToOne(targetEntity: BuildInstance::class, inversedBy: 'materialUsages')]
    #[ORM\JoinColumn(name: 'build_instance_id', nullable: false, onDelete: 'CASCADE')]
    private ?BuildInstance $buildInstance = null;

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

    #[ORM\Column(name: 'source_location_name', type: Types::STRING, length: 255, nullable: true)]
    private ?string $sourceLocationName = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\Positive]
    private int $quantity = 0;

    #[ORM\Column(name: 'from_project_stock', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $fromProjectStock = false;

    #[ORM\Column(name: 'serial_number', type: Types::STRING, length: 128, nullable: true)]
    private ?string $serialNumber = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'allocated_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $allocatedBy = null;

    public function getBuildInstance(): ?BuildInstance { return $this->buildInstance; }
    public function setBuildInstance(BuildInstance $buildInstance): self
    {
        if ($this->buildInstance === $buildInstance) { return $this; }
        $this->buildInstance?->getMaterialUsages()->removeElement($this);
        $this->buildInstance = $buildInstance;
        if (!$buildInstance->getMaterialUsages()->contains($this)) {
            $buildInstance->getMaterialUsages()->add($this);
        }

        return $this;
    }
    public function getPart(): ?Part { return $this->part; }
    public function setPart(?Part $part): self { $this->part = $part; if (null !== $part) { $this->partName = $part->getName(); $this->partReferenceId = $part->getId(); } return $this; }
    public function getPartName(): string { return $this->part?->getName() ?? $this->partName; }
    public function getPartReferenceId(): ?int { return $this->part?->getId() ?? $this->partReferenceId; }
    public function getSourcePartLot(): ?PartLot { return $this->sourcePartLot; }
    public function setSourcePartLot(?PartLot $lot): self { $this->sourcePartLot = $lot; if (null !== $lot) { $this->sourceLotName = $lot->getName(); $this->sourceLocationName = $lot->getStorageLocation()?->getFullPath(); } return $this; }
    public function getSourceLotName(): ?string { return $this->sourcePartLot?->getName() ?? $this->sourceLotName; }
    public function getSourceLocationName(): ?string { return $this->sourcePartLot?->getStorageLocation()?->getFullPath() ?? $this->sourceLocationName; }
    public function getQuantity(): int { return $this->quantity; }
    public function setQuantity(int $quantity): self { $this->quantity = $quantity; return $this; }
    public function isFromProjectStock(): bool { return $this->fromProjectStock; }
    public function setFromProjectStock(bool $value): self { $this->fromProjectStock = $value; return $this; }
    public function getSerialNumber(): ?string { return $this->serialNumber; }
    public function setSerialNumber(?string $value): self { $this->serialNumber = null === $value || '' === trim($value) ? null : trim($value); return $this; }
    public function getAllocatedBy(): ?User { return $this->allocatedBy; }
    public function setAllocatedBy(?User $user): self { $this->allocatedBy = $user; return $this; }
}

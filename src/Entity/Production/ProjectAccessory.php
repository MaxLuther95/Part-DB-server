<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\Parts\Part;
use App\Repository\Production\ProjectAccessoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProjectAccessoryRepository::class)]
#[ORM\Table(name: 'production_project_accessories')]
#[ORM\Index(name: 'IDX_PROD_ACCESSORY_PROJECT', columns: ['customer_project_id'])]
#[ORM\Index(name: 'IDX_PROD_ACCESSORY_POSITION', columns: ['project_position_id'])]
#[ORM\Index(name: 'IDX_PROD_ACCESSORY_SLOT', columns: ['source_slot_id'])]
#[ORM\Index(name: 'IDX_PROD_ACCESSORY_PART', columns: ['part_id'])]
class ProjectAccessory extends AbstractProductionEntity
{
    #[ORM\ManyToOne(targetEntity: CustomerProject::class, inversedBy: 'accessories')]
    #[ORM\JoinColumn(name: 'customer_project_id', nullable: false, onDelete: 'CASCADE')]
    private ?CustomerProject $customerProject = null;

    #[ORM\ManyToOne(targetEntity: ProjectPosition::class, inversedBy: 'partAssignments')]
    #[ORM\JoinColumn(name: 'project_position_id', nullable: true, onDelete: 'CASCADE')]
    private ?ProjectPosition $projectPosition = null;

    #[ORM\ManyToOne(targetEntity: SystemTemplateSlot::class)]
    #[ORM\JoinColumn(name: 'source_slot_id', nullable: true, onDelete: 'SET NULL')]
    private ?SystemTemplateSlot $sourceSlot = null;

    #[ORM\ManyToOne(targetEntity: Part::class)]
    #[ORM\JoinColumn(name: 'part_id', nullable: false)]
    #[Assert\NotNull]
    private ?Part $part = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    #[Assert\Positive]
    private int $quantity = 1;

    #[ORM\Column(name: 'serial_tracking', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $serialTracking = false;

    #[ORM\Column(type: Types::STRING, length: 255, options: ['default' => ''])]
    #[Assert\Length(max: 255)]
    private string $note = '';

    public function getCustomerProject(): ?CustomerProject
    {
        return $this->customerProject;
    }

    public function setCustomerProject(?CustomerProject $customerProject): self
    {
        $this->customerProject = $customerProject;

        return $this;
    }

    public function getProjectPosition(): ?ProjectPosition
    {
        return $this->projectPosition;
    }

    public function setProjectPosition(?ProjectPosition $projectPosition): self
    {
        $this->projectPosition = $projectPosition;
        if (null !== $projectPosition) {
            $this->customerProject = $projectPosition->getCustomerProject();
        }

        return $this;
    }

    public function getSourceSlot(): ?SystemTemplateSlot
    {
        return $this->sourceSlot;
    }

    public function setSourceSlot(?SystemTemplateSlot $sourceSlot): self
    {
        $this->sourceSlot = $sourceSlot;

        return $this;
    }

    public function getPart(): ?Part
    {
        return $this->part;
    }

    public function setPart(?Part $part): self
    {
        $this->part = $part;

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

    public function isSerialTracking(): bool
    {
        return $this->serialTracking;
    }

    public function setSerialTracking(bool $serialTracking): self
    {
        $this->serialTracking = $serialTracking;

        return $this;
    }

    public function getNote(): string
    {
        return $this->note;
    }

    public function setNote(string $note): self
    {
        $this->note = trim($note);

        return $this;
    }
}

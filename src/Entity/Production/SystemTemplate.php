<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\ProjectSystem\Project;
use App\Repository\Production\SystemTemplateRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SystemTemplateRepository::class)]
#[ORM\Table(name: 'production_system_templates')]
#[ORM\Index(name: 'IDX_PROD_SYSTEM_TEMPLATE_BASE', columns: ['base_project_id'])]
class SystemTemplate extends AbstractProductionEntity
{
    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'base_project_id', nullable: true, onDelete: 'SET NULL')]
    private ?Project $baseProject = null;

    #[ORM\Column(name: 'base_project_name', type: Types::STRING, length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $baseProjectName = null;

    #[ORM\Column(name: 'base_project_reference_id', type: Types::INTEGER, nullable: true)]
    private ?int $baseProjectReferenceId = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $description = '';

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    /** @var Collection<int, SystemTemplateSlot> */
    #[ORM\OneToMany(mappedBy: 'systemTemplate', targetEntity: SystemTemplateSlot::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'name' => 'ASC'])]
    private Collection $slots;

    public function __construct()
    {
        $this->slots = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = trim($name);

        return $this;
    }

    public function getBaseProject(): ?Project
    {
        return $this->baseProject;
    }

    public function setBaseProject(?Project $baseProject): self
    {
        $this->baseProject = $baseProject;
        if (null !== $baseProject) {
            $this->baseProjectName = $baseProject->getName();
            $this->baseProjectReferenceId = $baseProject->getId();
        }

        return $this;
    }

    public function getBaseProjectName(): ?string
    {
        return $this->baseProject?->getName() ?? $this->baseProjectName;
    }

    public function getBaseProjectReferenceId(): ?int
    {
        return $this->baseProject?->getId() ?? $this->baseProjectReferenceId;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    /** @return Collection<int, SystemTemplateSlot> */
    public function getSlots(): Collection
    {
        return $this->slots;
    }

    public function addSlot(SystemTemplateSlot $slot): self
    {
        if (!$this->slots->contains($slot)) {
            $this->slots->add($slot);
            $slot->setSystemTemplate($this);
        }

        return $this;
    }

    public function removeSlot(SystemTemplateSlot $slot): self
    {
        if ($this->slots->removeElement($slot) && $slot->getSystemTemplate() === $this) {
            $slot->setSystemTemplate(null);
        }

        return $this;
    }
}

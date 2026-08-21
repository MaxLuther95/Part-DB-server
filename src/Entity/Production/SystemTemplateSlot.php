<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\Parts\Part;
use App\Entity\ProjectSystem\Project;
use App\Repository\Production\SystemTemplateSlotRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: SystemTemplateSlotRepository::class)]
#[ORM\Table(name: 'production_system_template_slots')]
#[ORM\Index(name: 'IDX_PROD_SLOT_TEMPLATE', columns: ['system_template_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_PROD_SLOT_POSITION', columns: ['system_template_id', 'position'])]
#[UniqueEntity(fields: ['systemTemplate', 'position'], message: 'production.system_template.slot.position.unique')]
class SystemTemplateSlot extends AbstractProductionEntity
{
    #[ORM\ManyToOne(targetEntity: SystemTemplate::class, inversedBy: 'slots')]
    #[ORM\JoinColumn(name: 'system_template_id', nullable: false, onDelete: 'CASCADE')]
    private ?SystemTemplate $systemTemplate = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    private int $position = 0;

    #[ORM\Column(name: 'min_quantity', type: Types::INTEGER, options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    private int $minQuantity = 0;

    #[ORM\Column(name: 'max_quantity', type: Types::INTEGER, options: ['default' => 1])]
    #[Assert\Positive]
    private int $maxQuantity = 1;

    #[ORM\Column(name: 'serial_tracking', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $serialTracking = false;

    /** @var Collection<int, Project> */
    #[ORM\ManyToMany(targetEntity: Project::class)]
    #[ORM\JoinTable(name: 'production_system_template_slot_projects')]
    #[ORM\JoinColumn(name: 'slot_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'project_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $allowedProjects;

    /** @var Collection<int, SystemTemplate> */
    #[ORM\ManyToMany(targetEntity: SystemTemplate::class)]
    #[ORM\JoinTable(name: 'production_system_template_slot_templates')]
    #[ORM\JoinColumn(name: 'slot_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'allowed_template_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $allowedSystemTemplates;

    /** @var Collection<int, Part> */
    #[ORM\ManyToMany(targetEntity: Part::class)]
    #[ORM\JoinTable(name: 'production_system_template_slot_parts')]
    #[ORM\JoinColumn(name: 'slot_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'part_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $allowedParts;

    public function __construct()
    {
        $this->allowedProjects = new ArrayCollection();
        $this->allowedSystemTemplates = new ArrayCollection();
        $this->allowedParts = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getSystemTemplate(): ?SystemTemplate
    {
        return $this->systemTemplate;
    }

    public function setSystemTemplate(?SystemTemplate $systemTemplate): self
    {
        $this->systemTemplate = $systemTemplate;

        return $this;
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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function getMinQuantity(): int
    {
        return $this->minQuantity;
    }

    public function setMinQuantity(int $minQuantity): self
    {
        $this->minQuantity = $minQuantity;

        return $this;
    }

    public function getMaxQuantity(): int
    {
        return $this->maxQuantity;
    }

    public function setMaxQuantity(int $maxQuantity): self
    {
        $this->maxQuantity = $maxQuantity;

        return $this;
    }

    public function isRequired(): bool
    {
        return $this->minQuantity > 0;
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

    /** @return Collection<int, Project> */
    public function getAllowedProjects(): Collection
    {
        return $this->allowedProjects;
    }

    public function addAllowedProject(Project $project): self
    {
        if (!$this->allowedProjects->contains($project)) {
            $this->allowedProjects->add($project);
        }

        return $this;
    }

    public function removeAllowedProject(Project $project): self
    {
        $this->allowedProjects->removeElement($project);

        return $this;
    }

    public function allows(Project $project): bool
    {
        return $this->allowedProjects->contains($project);
    }

    /** @return Collection<int, SystemTemplate> */
    public function getAllowedSystemTemplates(): Collection
    {
        return $this->allowedSystemTemplates;
    }

    public function addAllowedSystemTemplate(SystemTemplate $template): self
    {
        if (!$this->allowedSystemTemplates->contains($template)) {
            $this->allowedSystemTemplates->add($template);
        }

        return $this;
    }

    public function removeAllowedSystemTemplate(SystemTemplate $template): self
    {
        $this->allowedSystemTemplates->removeElement($template);

        return $this;
    }

    /** @return Collection<int, Part> */
    public function getAllowedParts(): Collection
    {
        return $this->allowedParts;
    }

    public function addAllowedPart(Part $part): self
    {
        if (!$this->allowedParts->contains($part)) {
            $this->allowedParts->add($part);
        }

        return $this;
    }

    public function removeAllowedPart(Part $part): self
    {
        $this->allowedParts->removeElement($part);

        return $this;
    }

    #[Assert\Callback]
    public function validateQuantityRange(ExecutionContextInterface $context): void
    {
        if ($this->maxQuantity < $this->minQuantity) {
            $context->buildViolation('production.system_template.slot.quantity.invalid')
                ->atPath('maxQuantity')
                ->addViolation();
        }
        if (0 === $this->allowedSystemTemplates->count() && 0 === $this->allowedProjects->count() && 0 === $this->allowedParts->count()) {
            $context->buildViolation('production.system_template.slot.allowed_content.required')
                ->atPath('allowedProjects')
                ->addViolation();
        }
    }
}

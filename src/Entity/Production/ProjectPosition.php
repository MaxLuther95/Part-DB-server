<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\ProjectSystem\Project;
use App\Helpers\Production\{ManufacturingDefinition, ManufacturingSlot};
use App\Repository\Production\ProjectPositionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProjectPositionRepository::class)]
#[ORM\Table(name: 'production_project_positions')]
#[ORM\Index(name: 'IDX_PROD_POSITION_SYSTEM_TEMPLATE', columns: ['system_template_id'])]
#[ORM\Index(name: 'IDX_PROD_POSITION_SOURCE_SLOT', columns: ['source_slot_id'])]
class ProjectPosition extends AbstractProductionEntity
{
    #[ORM\ManyToOne(targetEntity: ManufacturingSnapshot::class, cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?ManufacturingSnapshot $manufacturingSnapshot = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $definitionKey = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $sourceSlotKey = null;

    public function getManufacturingSnapshot(): ?ManufacturingSnapshot { return $this->manufacturingSnapshot; }
    public function getDefinitionKey(): ?string { return $this->definitionKey; }
    public function getDefinition(): ?ManufacturingDefinition
    {
        return null === $this->definitionKey ? null : $this->manufacturingSnapshot?->getDefinition($this->definitionKey);
    }
    public function setManufacturingSnapshot(ManufacturingSnapshot $snapshot, string $key): self
    {
        $snapshot->getDefinition($key);
        if (null !== $this->manufacturingSnapshot && ($this->manufacturingSnapshot !== $snapshot || $this->definitionKey !== $key)) {
            throw new \DomainException('Der Fertigungsstand einer bestehenden Position ist fest. Bitte die Position löschen und neu anlegen.');
        }
        $this->manufacturingSnapshot = $snapshot;
        $this->definitionKey = $key;
        return $this;
    }
    /** @return iterable<SystemTemplateSlot|ManufacturingSlot> */
    public function getSlots(): iterable { return $this->getDefinition()?->getSlots() ?? $this->systemTemplate?->getSlots() ?? []; }

    #[ORM\ManyToOne(targetEntity: CustomerProject::class, inversedBy: 'positions')]
    #[ORM\JoinColumn(name: 'customer_project_id', nullable: false, onDelete: 'CASCADE')]
    private ?CustomerProject $customerProject = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'template_project_id', nullable: true, onDelete: 'SET NULL')]
    private ?Project $templateProject = null;

    #[ORM\ManyToOne(targetEntity: SystemTemplate::class)]
    #[ORM\JoinColumn(name: 'system_template_id', nullable: true, onDelete: 'SET NULL')]
    private ?SystemTemplate $systemTemplate = null;

    #[ORM\Column(name: 'content_name', type: Types::STRING, length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $contentName = null;

    #[ORM\Column(name: 'content_reference_type', type: Types::STRING, length: 32, nullable: true)]
    private ?string $contentReferenceType = null;

    #[ORM\Column(name: 'content_reference_id', type: Types::INTEGER, nullable: true)]
    private ?int $contentReferenceId = null;

    #[ORM\ManyToOne(targetEntity: SystemTemplateSlot::class)]
    #[ORM\JoinColumn(name: 'source_slot_id', nullable: true, onDelete: 'SET NULL')]
    private ?SystemTemplateSlot $sourceSlot = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_id', nullable: true, onDelete: 'SET NULL')]
    private ?self $parent = null;

    /** @var Collection<int, self> */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $children;

    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    #[Assert\PositiveOrZero]
    private int $position = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    #[Assert\Positive]
    private int $quantity = 1;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /** @var Collection<int, BuildInstance> */
    #[ORM\OneToMany(mappedBy: 'projectPosition', targetEntity: BuildInstance::class)]
    #[ORM\OrderBy(['serialNumber' => 'ASC'])]
    private Collection $buildInstances;

    /** @var Collection<int, ProjectAccessory> */
    #[ORM\OneToMany(mappedBy: 'projectPosition', targetEntity: ProjectAccessory::class)]
    #[ORM\OrderBy(['addedDate' => 'ASC'])]
    private Collection $partAssignments;

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->buildInstances = new ArrayCollection();
        $this->partAssignments = new ArrayCollection();
    }

    public function __toString(): string
    {
        return sprintf('%s – %s', $this->customerProject?->getProjectNumber() ?? '', $this->name);
    }

    public function getCustomerProject(): ?CustomerProject
    {
        return $this->customerProject;
    }

    public function setCustomerProject(?CustomerProject $customerProject): self
    {
        if ($this->customerProject === $customerProject) {
            return $this;
        }
        $previousProject = $this->customerProject;
        $previousProject?->getPositions()->removeElement($this);
        $this->customerProject = $customerProject;
        if (null !== $customerProject && !$customerProject->getPositions()->contains($this)) {
            $customerProject->getPositions()->add($this);
        }

        return $this;
    }

    public function getTemplateProject(): ?Project
    {
        return $this->templateProject;
    }

    public function setTemplateProject(?Project $templateProject): self
    {
        if (null !== $templateProject && null !== $this->definitionKey && $this->definitionKey !== 'project_'.$templateProject->getId()) {
            throw new \DomainException('Für einen anderen Fertigungsstand muss die Position neu angelegt werden.');
        }
        $this->templateProject = $templateProject;
        if (null !== $templateProject) {
            $this->systemTemplate = null;
            $this->contentName = $templateProject->getName();
            $this->contentReferenceType = 'project';
            $this->contentReferenceId = $templateProject->getId();
        }

        return $this;
    }

    public function getBuildProject(): ?Project
    {
        return $this->getBuildProjects()[0] ?? null;
    }

    /** @return list<Project> */
    public function getBuildProjects(): array
    {
        if (null !== $this->getDefinition()) { return $this->getDefinition()->getBuildProjects(); }
        if (null !== $this->templateProject) {
            return [$this->templateProject];
        }

        return null === $this->systemTemplate ? [] : array_values($this->systemTemplate->getBaseProjects()->toArray());
    }

    public function getSystemTemplate(): ?SystemTemplate
    {
        return $this->systemTemplate;
    }

    public function setSystemTemplate(?SystemTemplate $systemTemplate): self
    {
        if (null !== $systemTemplate && null !== $this->definitionKey && $this->definitionKey !== 'system_'.$systemTemplate->getId()) {
            throw new \DomainException('Für einen anderen Fertigungsstand muss die Position neu angelegt werden.');
        }
        $this->systemTemplate = $systemTemplate;
        if (null !== $systemTemplate) {
            $this->templateProject = null;
            $this->contentName = $systemTemplate->getName();
            $this->contentReferenceType = 'system_template';
            $this->contentReferenceId = $systemTemplate->getId();
        }

        return $this;
    }

    public function getContentName(): ?string
    {
        return $this->getDefinition()?->getName() ?? $this->systemTemplate?->getName() ?? $this->templateProject?->getName() ?? $this->contentName;
    }

    public function getContentReferenceType(): ?string
    {
        return null !== $this->systemTemplate ? 'system_template' : (null !== $this->templateProject ? 'project' : $this->contentReferenceType);
    }

    public function getContentReferenceId(): ?int
    {
        return $this->systemTemplate?->getId() ?? $this->templateProject?->getId() ?? $this->contentReferenceId;
    }

    public function getSourceSlot(): SystemTemplateSlot|ManufacturingSlot|null
    {
        $key = $this->sourceSlotKey ?? $this->sourceSlot?->getId();
        return (null === $key ? null : $this->parent?->getDefinition()?->getSlot($key)) ?? $this->sourceSlot;
    }

    public function setSourceSlot(SystemTemplateSlot|ManufacturingSlot|null $sourceSlot): self
    {
        $this->sourceSlotKey = $sourceSlot?->getId();
        $this->sourceSlot = $sourceSlot instanceof SystemTemplateSlot ? $sourceSlot : null;

        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): self
    {
        if ($parent === $this) {
            throw new \InvalidArgumentException('A project position cannot be its own parent.');
        }
        $this->parent = $parent;

        return $this;
    }

    /** @return Collection<int, self> */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function addChild(self $child): self
    {
        if (!$this->children->contains($child)) {
            $this->children->add($child);
            $child->setParent($this);
        }

        return $this;
    }

    public function removeChild(self $child): self
    {
        if ($this->children->removeElement($child) && $child->getParent() === $this) {
            $child->setParent(null);
        }

        return $this;
    }

    /** @return list<self> */
    public function getAssignmentsForSlot(SystemTemplateSlot|ManufacturingSlot $slot): array
    {
        return array_values($this->children->filter(
            static fn(self $child): bool => ManufacturingSlot::matches($child->getSourceSlot(), $slot),
        )->toArray());
    }

    public function getAssignmentForSlot(SystemTemplateSlot|ManufacturingSlot $slot): ?self
    {
        return $this->getAssignmentsForSlot($slot)[0] ?? null;
    }

    public function getDisplayOffsetForSlot(SystemTemplateSlot|ManufacturingSlot $slot): int
    {
        $offset = 0;
        foreach ($this->getSlots() as $templateSlot) {
            if (ManufacturingSlot::matches($templateSlot, $slot)) {
                return $offset;
            }

            $offset += max(1, count($this->getAssignmentsForSlot($templateSlot)));
        }

        return $offset;
    }

    public function getNextDisplayOffset(): int
    {
        $offset = 0;
        foreach ($this->getSlots() as $templateSlot) {
            $offset += max(1, count($this->getAssignmentsForSlot($templateSlot)));
        }

        return $offset;
    }

    public function getPartAssignmentForSlot(SystemTemplateSlot|ManufacturingSlot $slot): ?ProjectAccessory
    {
        foreach ($this->partAssignments as $assignment) {
            if (ManufacturingSlot::matches($assignment->getSourceSlot(), $slot)) {
                return $assignment;
            }
        }

        return null;
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

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): self
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $notes = null === $notes ? null : trim($notes);
        $this->notes = '' === $notes ? null : $notes;

        return $this;
    }

    /** @return Collection<int, BuildInstance> */
    public function getBuildInstances(): Collection
    {
        return $this->buildInstances;
    }

    /** @return Collection<int, ProjectAccessory> */
    public function getPartAssignments(): Collection
    {
        return $this->partAssignments;
    }

    #[Assert\Callback]
    public function validateContent(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        if (null === $this->systemTemplate && null === $this->templateProject && null === $this->contentName) {
            $context->buildViolation('production.project_position.template_required')->addViolation();
        }
    }
}

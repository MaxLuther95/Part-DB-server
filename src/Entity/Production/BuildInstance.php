<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\ProjectSystem\Project;
use App\Repository\Production\BuildInstanceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BuildInstanceRepository::class)]
#[ORM\Table(name: 'production_build_instances')]
#[ORM\Index(name: 'IDX_PROD_BUILD_SYSTEM_TEMPLATE', columns: ['system_template_id'])]
#[ORM\Index(name: 'IDX_PROD_BUILD_PARENT', columns: ['parent_id'])]
#[ORM\Index(name: 'IDX_PROD_BUILD_INSTALLED_SLOT', columns: ['installed_slot_id'])]
#[ORM\Index(name: 'IDX_PROD_BUILD_STATUS_DATE', columns: ['status', 'datetime_added'])]
#[ORM\Index(name: 'IDX_PROD_BUILD_ORDER_STATUS', columns: ['customer_project_id', 'status'])]
#[ORM\UniqueConstraint(name: 'UNIQ_PROD_BUILD_POSITION', columns: ['project_position_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_PROD_BUILD_PARENT_SLOT_INDEX', columns: ['parent_id', 'installed_slot_id', 'installed_slot_index'])]
#[UniqueEntity(fields: ['serialNumber'], message: 'production.build_instance.serial_number.unique')]
#[UniqueEntity(fields: ['projectPosition'], message: 'production.build_instance.project_position.unique')]
class BuildInstance extends AbstractProductionEntity
{
    #[ORM\Column(name: 'serial_number', type: Types::STRING, length: 128, unique: true, nullable: true)]
    #[Assert\Length(max: 128)]
    private ?string $serialNumber = null;

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

    #[ORM\ManyToOne(targetEntity: CustomerProject::class, inversedBy: 'buildInstances')]
    #[ORM\JoinColumn(name: 'customer_project_id', nullable: true, onDelete: 'SET NULL')]
    private ?CustomerProject $customerProject = null;

    #[ORM\ManyToOne(targetEntity: ProjectPosition::class, inversedBy: 'buildInstances')]
    #[ORM\JoinColumn(name: 'project_position_id', nullable: true, onDelete: 'SET NULL')]
    private ?ProjectPosition $projectPosition = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_id', nullable: true, onDelete: 'SET NULL')]
    private ?self $parent = null;

    #[ORM\ManyToOne(targetEntity: SystemTemplateSlot::class)]
    #[ORM\JoinColumn(name: 'installed_slot_id', nullable: true, onDelete: 'SET NULL')]
    private ?SystemTemplateSlot $installedSlot = null;

    #[ORM\Column(name: 'installed_slot_index', type: Types::INTEGER, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $installedSlotIndex = null;

    /** @var Collection<int, self> */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $children;

    /** @var Collection<int, BuildMaterialUsage> */
    #[ORM\OneToMany(mappedBy: 'buildInstance', targetEntity: BuildMaterialUsage::class)]
    #[ORM\OrderBy(['addedDate' => 'ASC'])]
    private Collection $materialUsages;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: BuildStatus::class)]
    private BuildStatus $status = BuildStatus::Planned;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $location = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'completed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->materialUsages = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->getDisplayIdentifier();
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

    public function getDisplayIdentifier(): string
    {
        return $this->serialNumber ?? (null !== $this->getId() ? '#'.$this->getId() : 'Ohne Seriennummer');
    }

    public function getTemplateProject(): ?Project
    {
        return $this->templateProject;
    }

    public function setTemplateProject(?Project $templateProject): self
    {
        $this->templateProject = $templateProject;
        if (null !== $templateProject) {
            $this->systemTemplate = null;
            $this->contentName = $templateProject->getName();
            $this->contentReferenceType = 'project';
            $this->contentReferenceId = $templateProject->getId();
        }

        return $this;
    }

    public function getSystemTemplate(): ?SystemTemplate
    {
        return $this->systemTemplate;
    }

    public function setSystemTemplate(?SystemTemplate $systemTemplate): self
    {
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
        return $this->systemTemplate?->getName() ?? $this->templateProject?->getName() ?? $this->contentName;
    }

    public function getContentReferenceType(): ?string
    {
        return null !== $this->systemTemplate ? 'system_template' : (null !== $this->templateProject ? 'project' : $this->contentReferenceType);
    }

    public function getContentReferenceId(): ?int
    {
        return $this->systemTemplate?->getId() ?? $this->templateProject?->getId() ?? $this->contentReferenceId;
    }

    public function getBuildProject(): ?Project
    {
        return $this->templateProject ?? $this->systemTemplate?->getBaseProject();
    }

    /** @return list<Project> */
    public function getBuildProjects(): array
    {
        if (null !== $this->templateProject) {
            return [$this->templateProject];
        }

        return null === $this->systemTemplate ? [] : array_values($this->systemTemplate->getBaseProjects()->toArray());
    }

    public function getCustomerProject(): ?CustomerProject
    {
        return $this->customerProject;
    }

    public function setCustomerProject(?CustomerProject $customerProject): self
    {
        if (null !== $customerProject && $this->projectPosition?->getCustomerProject() !== $customerProject) {
            throw new \InvalidArgumentException('A build instance must be assigned to a customer project through a project position.');
        }

        if (null === $customerProject) {
            return $this->setProjectPosition(null);
        }

        return $this->setProjectPosition($this->projectPosition);
    }

    public function getProjectPosition(): ?ProjectPosition
    {
        return $this->projectPosition;
    }

    public function setProjectPosition(?ProjectPosition $projectPosition): self
    {
        $previousPosition = $this->projectPosition;
        $previousProject = $this->customerProject;
        $customerProject = $projectPosition?->getCustomerProject();
        if ($previousPosition === $projectPosition && $previousProject === $customerProject) {
            return $this;
        }

        // Remove inverse references before changing the owning fields. Initializing a lazy
        // collection afterwards can hydrate this entity again and restore the old association.
        $previousPosition?->getBuildInstances()->removeElement($this);
        if ($previousProject !== $customerProject) {
            $previousProject?->getBuildInstances()->removeElement($this);
        }

        $this->projectPosition = $projectPosition;
        $this->customerProject = $customerProject;
        if (null !== $projectPosition && !$projectPosition->getBuildInstances()->contains($this)) {
            $projectPosition->getBuildInstances()->add($this);
        }
        if (null !== $customerProject && !$customerProject->getBuildInstances()->contains($this)) {
            $customerProject->getBuildInstances()->add($this);
        }

        if (null !== $projectPosition) {
            if (null !== $projectPosition->getSystemTemplate()) {
                $this->setSystemTemplate($projectPosition->getSystemTemplate());
            } else {
                $this->setTemplateProject($projectPosition->getTemplateProject());
            }
        }

        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): self
    {
        if ($parent === $this) {
            throw new \InvalidArgumentException('A build instance cannot be its own parent.');
        }
        if ($this->parent === $parent) {
            return $this;
        }
        $previousParent = $this->parent;
        $previousParent?->getChildren()->removeElement($this);
        $this->parent = $parent;
        if (null === $parent) {
            $this->installedSlot = null;
            $this->installedSlotIndex = null;
        }
        if (null !== $parent && !$parent->getChildren()->contains($this)) {
            $parent->getChildren()->add($this);
        }

        return $this;
    }

    public function getInstalledSlot(): ?SystemTemplateSlot
    {
        return $this->installedSlot;
    }

    public function setInstalledSlot(?SystemTemplateSlot $installedSlot): self
    {
        $this->installedSlot = $installedSlot;
        if (null === $installedSlot) {
            $this->installedSlotIndex = null;
        }

        return $this;
    }

    public function getInstalledSlotIndex(): ?int
    {
        return $this->installedSlotIndex;
    }

    public function setInstalledSlotIndex(?int $installedSlotIndex): self
    {
        $this->installedSlotIndex = $installedSlotIndex;

        return $this;
    }

    /** @return Collection<int, self> */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    /** @return Collection<int, BuildMaterialUsage> */
    public function getMaterialUsages(): Collection
    {
        return $this->materialUsages;
    }

    public function getStatus(): BuildStatus
    {
        return $this->status;
    }

    public function setStatus(BuildStatus $status): self
    {
        $this->status = $status;
        if (BuildStatus::Completed === $status && null === $this->completedAt) {
            $this->completedAt = new \DateTimeImmutable('now');
        }

        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): self
    {
        $location = null === $location ? null : trim($location);
        $this->location = '' === $location ? null : $location;

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

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): self
    {
        $this->completedAt = $completedAt;

        return $this;
    }

    #[Assert\Callback]
    public function validateContent(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        if (null === $this->systemTemplate && null === $this->templateProject && null === $this->contentName) {
            $context->buildViolation('production.build_instance.template_required')->addViolation();
        }
        if (null === $this->serialNumber && null === $this->notes) {
            $context->buildViolation('production.build_instance.notes_required_without_serial')
                ->atPath('notes')
                ->addViolation();
        }
        if (null === $this->serialNumber
            && in_array($this->customerProject?->getStatus(), [CustomerProjectStatus::Completed, CustomerProjectStatus::Delivered], true)) {
            $context->buildViolation('production.build_instance.serial_required_for_completed_project')
                ->atPath('serialNumber')
                ->addViolation();
        }
        if (null !== $this->installedSlot) {
            if (null === $this->parent || $this->parent->getSystemTemplate() !== $this->installedSlot->getSystemTemplate()) {
                $context->buildViolation('Der gespeicherte Steckplatz gehört nicht zum übergeordneten Gerät.')
                    ->atPath('installedSlot')
                    ->addViolation();
            } elseif (!$this->slotAllowsCurrentContent($this->installedSlot)) {
                $context->buildViolation('Der Inhalt ist für den gespeicherten Steckplatz nicht erlaubt.')
                    ->atPath('installedSlot')
                    ->addViolation();
            }
        }
    }

    private function slotAllowsCurrentContent(SystemTemplateSlot $slot): bool
    {
        return (null !== $this->systemTemplate && $slot->getAllowedSystemTemplates()->contains($this->systemTemplate))
            || (null !== $this->templateProject && $slot->getAllowedProjects()->contains($this->templateProject));
    }
}

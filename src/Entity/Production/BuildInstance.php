<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\ProjectSystem\Project;
use App\Repository\Production\BuildInstanceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BuildInstanceRepository::class)]
#[ORM\Table(name: 'production_build_instances')]
#[ORM\Index(name: 'IDX_PROD_BUILD_SYSTEM_TEMPLATE', columns: ['system_template_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_PROD_BUILD_POSITION', columns: ['project_position_id'])]
#[UniqueEntity(fields: ['serialNumber'], message: 'production.build_instance.serial_number.unique')]
#[UniqueEntity(fields: ['projectPosition'], message: 'production.build_instance.project_position.unique')]
class BuildInstance extends AbstractProductionEntity
{
    #[ORM\Column(name: 'serial_number', type: Types::STRING, length: 128, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 128)]
    private string $serialNumber = '';

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

    #[ORM\Column(type: Types::STRING, length: 32, enumType: BuildStatus::class)]
    private BuildStatus $status = BuildStatus::Planned;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $location = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'completed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __toString(): string
    {
        return $this->serialNumber;
    }

    public function getSerialNumber(): string
    {
        return $this->serialNumber;
    }

    public function setSerialNumber(string $serialNumber): self
    {
        $this->serialNumber = trim($serialNumber);

        return $this;
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

    public function getCustomerProject(): ?CustomerProject
    {
        return $this->customerProject;
    }

    public function setCustomerProject(?CustomerProject $customerProject): self
    {
        if (null !== $customerProject && $this->projectPosition?->getCustomerProject() !== $customerProject) {
            throw new \InvalidArgumentException('A build instance must be assigned to a customer project through a project position.');
        }

        $this->customerProject = $customerProject;
        if (null === $customerProject) {
            $this->projectPosition = null;
        }

        return $this;
    }

    public function getProjectPosition(): ?ProjectPosition
    {
        return $this->projectPosition;
    }

    public function setProjectPosition(?ProjectPosition $projectPosition): self
    {
        $this->projectPosition = $projectPosition;
        $this->customerProject = $projectPosition?->getCustomerProject();

        if (null !== $projectPosition) {
            if (null !== $projectPosition->getSystemTemplate()) {
                $this->setSystemTemplate($projectPosition->getSystemTemplate());
            } else {
                $this->setTemplateProject($projectPosition->getTemplateProject());
            }
        }

        return $this;
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
    }
}

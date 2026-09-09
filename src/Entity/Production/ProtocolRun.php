<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\UserSystem\User;
use App\Repository\Production\ProtocolRunRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProtocolRunRepository::class)]
#[ORM\Table(name: 'production_protocol_runs')]
#[ORM\Index(name: 'IDX_PROD_PROTOCOL_RUN_BUILD', columns: ['build_instance_id'])]
#[ORM\Index(name: 'IDX_PROD_PROTOCOL_RUN_REV', columns: ['revision_id'])]
#[ORM\Index(name: 'IDX_PROD_PROTOCOL_RUN_STARTED', columns: ['started_by_id'])]
#[ORM\Index(name: 'IDX_PROD_PROTOCOL_RUN_EDITED', columns: ['last_edited_by_id'])]
#[ORM\Index(name: 'IDX_PROD_PROTOCOL_RUN_COMPLETED', columns: ['completed_by_id'])]
#[ORM\Index(name: 'IDX_PROD_PROTOCOL_RUN_INVALIDATED', columns: ['invalidated_by_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_PROD_PROTOCOL_RUN_NUMBER', columns: ['build_instance_id', 'run_number'])]
class ProtocolRun extends AbstractProductionEntity
{
    #[ORM\ManyToOne(targetEntity: BuildInstance::class, inversedBy: 'protocolRuns')]
    #[ORM\JoinColumn(name: 'build_instance_id', nullable: false, onDelete: 'CASCADE')]
    private ?BuildInstance $buildInstance = null;

    #[ORM\ManyToOne(targetEntity: ProtocolTemplateRevision::class)]
    #[ORM\JoinColumn(name: 'revision_id', nullable: false)]
    private ?ProtocolTemplateRevision $revision = null;

    #[ORM\Column(name: 'run_number', type: Types::INTEGER)]
    #[Assert\Positive]
    private int $runNumber = 1;

    #[ORM\Column(type: Types::STRING, length: 24, enumType: ProtocolRunStatus::class)]
    private ProtocolRunStatus $status = ProtocolRunStatus::Draft;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'started_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $startedBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'last_edited_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $lastEditedBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'completed_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $completedBy = null;

    #[ORM\Column(name: 'completed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'invalidated_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $invalidatedBy = null;

    #[ORM\Column(name: 'invalidated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $invalidatedAt = null;

    #[ORM\Column(name: 'invalid_reason', type: Types::TEXT, nullable: true)]
    private ?string $invalidReason = null;

    /**
     * @var Collection<int, ProtocolRunSectionRow>
     */
    #[ORM\OneToMany(mappedBy: 'run', targetEntity: ProtocolRunSectionRow::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy([
        'id' => 'ASC',
    ])]
    private Collection $rows;

    public function __construct()
    {
        $this->rows = new ArrayCollection();
    }

    public function __toString(): string
    {
        return sprintf('%s #%d', $this->revision?->getTemplate()?->getName() ?? 'Laufzettel', $this->runNumber);
    }

    public function getBuildInstance(): ?BuildInstance
    {
        return $this->buildInstance;
    }

    public function setBuildInstance(BuildInstance $buildInstance): self
    {
        $this->buildInstance = $buildInstance;

        return $this;
    }

    public function getRevision(): ?ProtocolTemplateRevision
    {
        return $this->revision;
    }

    public function setRevision(ProtocolTemplateRevision $revision): self
    {
        if (ProtocolRevisionStatus::Published !== $revision->getStatus()) {
            throw new \InvalidArgumentException('A protocol run requires a published template revision.');
        }
        $this->revision = $revision;

        return $this;
    }

    public function getRunNumber(): int
    {
        return $this->runNumber;
    }

    public function setRunNumber(int $runNumber): self
    {
        $this->runNumber = $runNumber;

        return $this;
    }

    public function getStatus(): ProtocolRunStatus
    {
        return $this->status;
    }

    public function getStartedBy(): ?User
    {
        return $this->startedBy;
    }

    public function setStartedBy(?User $startedBy): self
    {
        $this->startedBy = $startedBy;
        $this->lastEditedBy = $startedBy;

        return $this;
    }

    public function getLastEditedBy(): ?User
    {
        return $this->lastEditedBy;
    }

    public function touch(?User $user): void
    {
        $this->assertEditable();
        $this->lastEditedBy = $user;
    }

    public function getCompletedBy(): ?User
    {
        return $this->completedBy;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getInvalidatedBy(): ?User
    {
        return $this->invalidatedBy;
    }

    public function getInvalidatedAt(): ?\DateTimeImmutable
    {
        return $this->invalidatedAt;
    }

    public function getInvalidReason(): ?string
    {
        return $this->invalidReason;
    }

    /**
     * @return Collection<int, ProtocolRunSectionRow>
     */
    public function getRows(): Collection
    {
        return $this->rows;
    }

    public function addRow(ProtocolRunSectionRow $row): self
    {
        $this->assertEditable();
        if (! $this->rows->contains($row)) {
            $this->rows->add($row);
            $row->setRun($this);
        }

        return $this;
    }

    public function getRowForSection(ProtocolTemplateSection $section): ?ProtocolRunSectionRow
    {
        foreach ($this->rows as $row) {
            if ($row->getSection() === $section) {
                return $row;
            }
        }

        return null;
    }

    public function complete(?User $user): void
    {
        $this->assertEditable();
        $this->status = ProtocolRunStatus::Completed;
        $this->completedAt = new \DateTimeImmutable('now');
        $this->completedBy = $user;
        $this->lastEditedBy = $user;
    }

    public function invalidate(string $reason, ?User $user): void
    {
        if (ProtocolRunStatus::Completed !== $this->status) {
            throw new \LogicException('Only a completed protocol run can be invalidated.');
        }
        $reason = trim($reason);
        if ('' === $reason) {
            throw new \InvalidArgumentException('A reason is required to invalidate a protocol run.');
        }
        $this->status = ProtocolRunStatus::Invalid;
        $this->invalidReason = $reason;
        $this->invalidatedAt = new \DateTimeImmutable('now');
        $this->invalidatedBy = $user;
    }

    public function assertEditable(): void
    {
        if (ProtocolRunStatus::Draft !== $this->status) {
            throw new \LogicException('A completed or invalid protocol run is immutable.');
        }
    }
}

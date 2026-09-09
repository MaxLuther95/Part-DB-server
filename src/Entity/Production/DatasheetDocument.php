<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\UserSystem\User;
use App\Repository\Production\DatasheetDocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DatasheetDocumentRepository::class)]
#[ORM\Table(name: 'production_datasheet_documents')]
#[ORM\Index(name: 'IDX_PROD_DATASHEET_DOC_BUILD', columns: ['build_instance_id'])]
#[ORM\Index(name: 'IDX_PROD_DATASHEET_DOC_TEMPLATE', columns: ['template_id'])]
#[ORM\Index(name: 'IDX_PROD_DATASHEET_DOC_REVISION', columns: ['revision_id'])]
#[ORM\Index(name: 'IDX_PROD_DATASHEET_DOC_USER', columns: ['released_by_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_PROD_DATASHEET_DOC_NUMBER', columns: ['build_instance_id', 'template_id', 'document_revision'])]
#[ORM\UniqueConstraint(name: 'UNIQ_PROD_DATASHEET_DOC_FILE', columns: ['stored_filename'])]
#[ORM\HasLifecycleCallbacks]
class DatasheetDocument extends AbstractProductionEntity
{
    #[ORM\ManyToOne(targetEntity: BuildInstance::class, inversedBy: 'datasheets')]
    #[ORM\JoinColumn(name: 'build_instance_id', nullable: false, onDelete: 'RESTRICT')]
    private ?BuildInstance $buildInstance = null;

    #[ORM\ManyToOne(targetEntity: DatasheetTemplate::class)]
    #[ORM\JoinColumn(name: 'template_id', nullable: false, onDelete: 'RESTRICT')]
    private ?DatasheetTemplate $template = null;

    #[ORM\ManyToOne(targetEntity: DatasheetTemplateRevision::class)]
    #[ORM\JoinColumn(name: 'revision_id', nullable: false, onDelete: 'RESTRICT')]
    private ?DatasheetTemplateRevision $revision = null;

    #[ORM\Column(name: 'document_revision', type: Types::INTEGER)]
    #[Assert\Positive]
    private int $documentRevision = 1;

    #[ORM\Column(name: 'original_filename', type: Types::STRING, length: 255)]
    private string $originalFilename = '';

    #[ORM\Column(name: 'stored_filename', type: Types::STRING, length: 255)]
    private string $storedFilename = '';

    #[ORM\Column(name: 'file_size', type: Types::INTEGER)]
    #[Assert\Positive]
    private int $fileSize = 0;

    #[ORM\Column(name: 'sha256_checksum', type: Types::STRING, length: 64)]
    #[Assert\Regex(pattern: '/^[a-f0-9]{64}$/D')]
    private string $sha256Checksum = '';

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(name: 'source_snapshot', type: Types::JSON)]
    private array $sourceSnapshot = [];

    #[ORM\Column(name: 'released_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $releasedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'released_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $releasedBy = null;

    public function __construct()
    {
        $this->releasedAt = new \DateTimeImmutable('now');
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

    public function getTemplate(): ?DatasheetTemplate
    {
        return $this->template;
    }

    public function setTemplate(DatasheetTemplate $template): self
    {
        $this->template = $template;

        return $this;
    }

    public function getRevision(): ?DatasheetTemplateRevision
    {
        return $this->revision;
    }

    public function setRevision(DatasheetTemplateRevision $revision): self
    {
        if (ProtocolRevisionStatus::Published !== $revision->getStatus()) {
            throw new \InvalidArgumentException('An official datasheet requires a published template revision.');
        }
        $this->revision = $revision;

        return $this;
    }

    public function getDocumentRevision(): int
    {
        return $this->documentRevision;
    }

    public function setDocumentRevision(int $documentRevision): self
    {
        $this->documentRevision = $documentRevision;

        return $this;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(string $originalFilename): self
    {
        $this->originalFilename = $originalFilename;

        return $this;
    }

    public function getStoredFilename(): string
    {
        return $this->storedFilename;
    }

    public function setStoredFilename(string $storedFilename): self
    {
        $this->storedFilename = $storedFilename;

        return $this;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function setFileSize(int $fileSize): self
    {
        $this->fileSize = $fileSize;

        return $this;
    }

    public function getSha256Checksum(): string
    {
        return $this->sha256Checksum;
    }

    public function setSha256Checksum(string $sha256Checksum): self
    {
        $this->sha256Checksum = $sha256Checksum;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSourceSnapshot(): array
    {
        return $this->sourceSnapshot;
    }

    /**
     * @param array<string, mixed> $sourceSnapshot
     */
    public function setSourceSnapshot(array $sourceSnapshot): self
    {
        $this->sourceSnapshot = $sourceSnapshot;

        return $this;
    }

    public function getReleasedAt(): \DateTimeImmutable
    {
        return $this->releasedAt;
    }

    public function getReleasedBy(): ?User
    {
        return $this->releasedBy;
    }

    public function setReleasedBy(?User $releasedBy): self
    {
        $this->releasedBy = $releasedBy;

        return $this;
    }

    #[ORM\PreUpdate]
    public function preventModificationAfterRelease(): never
    {
        throw new \LogicException('Released datasheets are immutable; create a new document revision instead.');
    }
}

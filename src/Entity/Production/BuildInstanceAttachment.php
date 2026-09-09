<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\UserSystem\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'production_build_instance_attachments')]
#[ORM\Index(name: 'IDX_PROD_BUILD_ATTACHMENT_BUILD', columns: ['build_instance_id'])]
#[ORM\Index(name: 'IDX_PROD_BUILD_ATTACHMENT_USER', columns: ['uploaded_by_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_PROD_BUILD_ATTACHMENT_FILE', columns: ['stored_filename'])]
class BuildInstanceAttachment extends AbstractProductionEntity
{
    #[ORM\ManyToOne(targetEntity: BuildInstance::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(name: 'build_instance_id', nullable: false, onDelete: 'CASCADE')]
    private ?BuildInstance $buildInstance = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    private string $originalFilename = '';

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $storedFilename = '';

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $mimeType = 'application/octet-stream';

    #[ORM\Column(type: Types::INTEGER)]
    private int $fileSize = 0;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $category = 'Allgemein';

    #[ORM\Column(name: 'sha256_checksum', type: Types::STRING, length: 64)]
    private string $sha256Checksum = '';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'uploaded_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $uploadedBy = null;

    public function getBuildInstance(): ?BuildInstance
    {
        return $this->buildInstance;
    }

    public function setBuildInstance(BuildInstance $buildInstance): self
    {
        $this->buildInstance = $buildInstance;

        return $this;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(string $filename): self
    {
        $this->originalFilename = trim($filename);

        return $this;
    }

    public function getStoredFilename(): string
    {
        return $this->storedFilename;
    }

    public function setStoredFilename(string $filename): self
    {
        $this->storedFilename = trim($filename);

        return $this;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): self
    {
        $this->mimeType = trim($mimeType);

        return $this;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function setFileSize(int $fileSize): self
    {
        $this->fileSize = max(0, $fileSize);

        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): self
    {
        $category = mb_substr(trim($category), 0, 64);
        $this->category = '' === $category ? 'Allgemein' : $category;

        return $this;
    }

    public function getSha256Checksum(): string
    {
        return $this->sha256Checksum;
    }

    public function setSha256Checksum(string $checksum): self
    {
        $checksum = strtolower(trim($checksum));
        if (1 !== preg_match('/^[a-f0-9]{64}$/D', $checksum)) {
            throw new \InvalidArgumentException('Invalid SHA-256 checksum.');
        }
        $this->sha256Checksum = $checksum;

        return $this;
    }

    public function getUploadedBy(): ?User
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(?User $uploadedBy): self
    {
        $this->uploadedBy = $uploadedBy;

        return $this;
    }
}

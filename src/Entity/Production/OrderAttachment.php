<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Repository\Production\OrderAttachmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: OrderAttachmentRepository::class)]
#[ORM\Table(name: 'production_order_attachments')]
class OrderAttachment extends AbstractProductionEntity
{
    #[ORM\ManyToOne(targetEntity: CustomerProject::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(name: 'customer_project_id', nullable: false, onDelete: 'CASCADE')]
    private ?CustomerProject $order = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    private string $originalFilename = '';

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    private string $storedFilename = '';

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $mimeType = 'application/octet-stream';

    #[ORM\Column(type: Types::INTEGER)]
    private int $fileSize = 0;

    public function getOrder(): ?CustomerProject { return $this->order; }
    public function setOrder(CustomerProject $order): self
    {
        if ($this->order === $order) {
            return $this;
        }
        $this->order?->removeAttachment($this);
        $this->order = $order;
        if (!$order->getAttachments()->contains($this)) {
            $order->addAttachment($this);
        }

        return $this;
    }
    public function getOriginalFilename(): string { return $this->originalFilename; }
    public function setOriginalFilename(string $filename): self { $this->originalFilename = trim($filename); return $this; }
    public function getStoredFilename(): string { return $this->storedFilename; }
    public function setStoredFilename(string $filename): self { $this->storedFilename = trim($filename); return $this; }
    public function getMimeType(): string { return $this->mimeType; }
    public function setMimeType(string $mimeType): self { $this->mimeType = trim($mimeType); return $this; }
    public function getFileSize(): int { return $this->fileSize; }
    public function setFileSize(int $fileSize): self { $this->fileSize = max(0, $fileSize); return $this; }
}

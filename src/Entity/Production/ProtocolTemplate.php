<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Repository\Production\ProtocolTemplateRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProtocolTemplateRepository::class)]
#[ORM\Table(name: 'production_protocol_templates')]
class ProtocolTemplate extends AbstractProductionEntity
{
    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $description = '';

    #[ORM\Column(type: Types::BOOLEAN, options: [
        'default' => true,
    ])]
    private bool $active = true;

    /**
     * @var Collection<int, ProtocolTemplateRevision>
     */
    #[ORM\OneToMany(mappedBy: 'template', targetEntity: ProtocolTemplateRevision::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy([
        'revisionNumber' => 'DESC',
    ])]
    private Collection $revisions;

    public function __construct()
    {
        $this->revisions = new ArrayCollection();
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

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = trim((string) $description);

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

    /**
     * @return Collection<int, ProtocolTemplateRevision>
     */
    public function getRevisions(): Collection
    {
        return $this->revisions;
    }

    public function addRevision(ProtocolTemplateRevision $revision): self
    {
        if (! $this->revisions->contains($revision)) {
            $this->revisions->add($revision);
            $revision->setTemplate($this);
        }

        return $this;
    }

    public function getDraftRevision(): ?ProtocolTemplateRevision
    {
        foreach ($this->revisions as $revision) {
            if (ProtocolRevisionStatus::Draft === $revision->getStatus()) {
                return $revision;
            }
        }

        return null;
    }

    public function getPublishedRevision(): ?ProtocolTemplateRevision
    {
        foreach ($this->revisions as $revision) {
            if (ProtocolRevisionStatus::Published === $revision->getStatus()) {
                return $revision;
            }
        }

        return null;
    }

    public function getNextRevisionNumber(): int
    {
        $highest = 0;
        foreach ($this->revisions as $revision) {
            $highest = max($highest, $revision->getRevisionNumber());
        }

        return $highest + 1;
    }
}

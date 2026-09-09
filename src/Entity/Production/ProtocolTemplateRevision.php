<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\UserSystem\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'production_protocol_template_revisions')]
#[ORM\Index(name: 'IDX_PROD_PROTOCOL_REV_TEMPLATE', columns: ['template_id'])]
#[ORM\Index(name: 'IDX_PROD_PROTOCOL_REV_USER', columns: ['published_by_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_PROD_PROTOCOL_REVISION', columns: ['template_id', 'revision_number'])]
class ProtocolTemplateRevision extends AbstractProductionEntity
{
    #[ORM\ManyToOne(targetEntity: ProtocolTemplate::class, inversedBy: 'revisions')]
    #[ORM\JoinColumn(name: 'template_id', nullable: false, onDelete: 'CASCADE')]
    private ?ProtocolTemplate $template = null;

    #[ORM\Column(name: 'revision_number', type: Types::INTEGER)]
    #[Assert\Positive]
    private int $revisionNumber = 1;

    #[ORM\Column(type: Types::STRING, length: 24, enumType: ProtocolRevisionStatus::class)]
    private ProtocolRevisionStatus $status = ProtocolRevisionStatus::Draft;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $changeNote = null;

    #[ORM\Column(name: 'published_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'published_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $publishedBy = null;

    /**
     * @var Collection<int, ProtocolTemplateSection>
     */
    #[ORM\OneToMany(mappedBy: 'revision', targetEntity: ProtocolTemplateSection::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy([
        'position' => 'ASC',
        'id' => 'ASC',
    ])]
    private Collection $sections;

    public function __construct()
    {
        $this->sections = new ArrayCollection();
    }

    public function getTemplate(): ?ProtocolTemplate
    {
        return $this->template;
    }

    public function setTemplate(ProtocolTemplate $template): self
    {
        $this->template = $template;

        return $this;
    }

    public function getRevisionNumber(): int
    {
        return $this->revisionNumber;
    }

    public function setRevisionNumber(int $revisionNumber): self
    {
        $this->revisionNumber = $revisionNumber;

        return $this;
    }

    public function getStatus(): ProtocolRevisionStatus
    {
        return $this->status;
    }

    public function getChangeNote(): ?string
    {
        return $this->changeNote;
    }

    public function setChangeNote(?string $changeNote): self
    {
        $changeNote = trim((string) $changeNote);
        $this->changeNote = '' === $changeNote ? null : $changeNote;

        return $this;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getPublishedBy(): ?User
    {
        return $this->publishedBy;
    }

    /**
     * @return Collection<int, ProtocolTemplateSection>
     */
    public function getSections(): Collection
    {
        return $this->sections;
    }

    public function addSection(ProtocolTemplateSection $section): self
    {
        $this->assertEditable();
        if (! $this->sections->contains($section)) {
            $this->sections->add($section);
            $section->setRevision($this);
        }

        return $this;
    }

    public function removeSection(ProtocolTemplateSection $section): self
    {
        $this->assertEditable();
        $this->sections->removeElement($section);

        return $this;
    }

    public function publish(?User $user): void
    {
        $this->assertEditable();
        $this->status = ProtocolRevisionStatus::Published;
        $this->publishedAt = new \DateTimeImmutable('now');
        $this->publishedBy = $user;
    }

    public function retire(): void
    {
        if (ProtocolRevisionStatus::Published !== $this->status) {
            throw new \LogicException('Only a published protocol revision can be retired.');
        }
        $this->status = ProtocolRevisionStatus::Retired;
    }

    public function assertEditable(): void
    {
        if (ProtocolRevisionStatus::Draft !== $this->status) {
            throw new \LogicException('Published protocol revisions are immutable.');
        }
    }
}

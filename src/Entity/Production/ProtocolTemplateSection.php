<?php

declare(strict_types=1);

namespace App\Entity\Production;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'production_protocol_template_sections')]
#[ORM\Index(name: 'IDX_PROD_PROTOCOL_SECTION_REV', columns: ['revision_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_PROD_PROTOCOL_SECTION_KEY', columns: ['revision_id', 'stable_key'])]
#[ORM\UniqueConstraint(name: 'UNIQ_PROD_PROTOCOL_SECTION_POSITION', columns: ['revision_id', 'position'])]
class ProtocolTemplateSection extends AbstractProductionEntity
{
    #[ORM\ManyToOne(targetEntity: ProtocolTemplateRevision::class, inversedBy: 'sections')]
    #[ORM\JoinColumn(name: 'revision_id', nullable: false, onDelete: 'CASCADE')]
    private ?ProtocolTemplateRevision $revision = null;

    #[ORM\Column(name: 'stable_key', type: Types::STRING, length: 36)]
    private string $stableKey;

    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $description = '';

    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\PositiveOrZero]
    private int $position = 0;

    /**
     * @var Collection<int, ProtocolTemplateField>
     */
    #[ORM\OneToMany(mappedBy: 'section', targetEntity: ProtocolTemplateField::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy([
        'position' => 'ASC',
        'id' => 'ASC',
    ])]
    private Collection $fields;

    public function __construct(?string $stableKey = null)
    {
        $this->stableKey = $stableKey ?? Uuid::v7()->toRfc4122();
        $this->fields = new ArrayCollection();
    }

    public function getRevision(): ?ProtocolTemplateRevision
    {
        return $this->revision;
    }

    public function setRevision(ProtocolTemplateRevision $revision): self
    {
        $revision->assertEditable();
        $this->revision = $revision;

        return $this;
    }

    public function getStableKey(): string
    {
        return $this->stableKey;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->assertEditable();
        $this->name = trim($name);

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->assertEditable();
        $this->description = trim((string) $description);

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->assertEditable();
        $this->position = $position;

        return $this;
    }

    /**
     * @return Collection<int, ProtocolTemplateField>
     */
    public function getFields(): Collection
    {
        return $this->fields;
    }

    public function addField(ProtocolTemplateField $field): self
    {
        $this->assertEditable();
        if (! $this->fields->contains($field)) {
            $this->fields->add($field);
            $field->setSection($this);
        }

        return $this;
    }

    public function removeField(ProtocolTemplateField $field): self
    {
        $this->assertEditable();
        $this->fields->removeElement($field);

        return $this;
    }

    private function assertEditable(): void
    {
        $this->revision?->assertEditable();
    }
}

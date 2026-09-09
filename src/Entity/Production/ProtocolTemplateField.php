<?php

declare(strict_types=1);

namespace App\Entity\Production;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'production_protocol_template_fields')]
#[ORM\Index(name: 'IDX_PROD_PROTOCOL_FIELD_SECTION', columns: ['section_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_PROD_PROTOCOL_FIELD_KEY', columns: ['section_id', 'stable_key'])]
#[ORM\UniqueConstraint(name: 'UNIQ_PROD_PROTOCOL_FIELD_POSITION', columns: ['section_id', 'position'])]
class ProtocolTemplateField extends AbstractProductionEntity
{
    #[ORM\ManyToOne(targetEntity: ProtocolTemplateSection::class, inversedBy: 'fields')]
    #[ORM\JoinColumn(name: 'section_id', nullable: false, onDelete: 'CASCADE')]
    private ?ProtocolTemplateSection $section = null;

    #[ORM\Column(name: 'stable_key', type: Types::STRING, length: 36)]
    private string $stableKey;

    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $label = '';

    #[ORM\Column(type: Types::STRING, length: 32, enumType: ProtocolFieldType::class)]
    private ProtocolFieldType $type = ProtocolFieldType::Text;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    #[Assert\Length(max: 32)]
    private ?string $unit = null;

    #[ORM\Column(name: 'help_text', type: Types::TEXT, nullable: true)]
    private ?string $helpText = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\PositiveOrZero]
    private int $position = 0;

    #[ORM\Column(name: 'layout_columns', type: Types::SMALLINT, options: [
        'default' => 6,
    ])]
    #[Assert\Choice(choices: [3, 6, 9, 12])]
    private int $layoutColumns = 6;

    #[ORM\Column(name: 'start_new_row', type: Types::BOOLEAN, options: [
        'default' => false,
    ])]
    private bool $startNewRow = false;

    #[ORM\Column(type: Types::BOOLEAN, options: [
        'default' => false,
    ])]
    private bool $required = true;

    /**
     * @var list<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $options = null;

    public function __construct(?string $stableKey = null)
    {
        $this->stableKey = $stableKey ?? Uuid::v7()->toRfc4122();
    }

    public function getSection(): ?ProtocolTemplateSection
    {
        return $this->section;
    }

    public function setSection(ProtocolTemplateSection $section): self
    {
        $section->getRevision()?->assertEditable();
        $this->section = $section;

        return $this;
    }

    public function getStableKey(): string
    {
        return $this->stableKey;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->assertEditable();
        $this->label = trim($label);

        return $this;
    }

    public function getType(): ProtocolFieldType
    {
        return $this->type;
    }

    public function setType(ProtocolFieldType $type): self
    {
        $this->assertEditable();
        $this->type = $type;
        if (ProtocolFieldType::StaticNote === $type) {
            $this->required = false;
            $this->unit = null;
            $this->options = null;
        }

        return $this;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function setUnit(?string $unit): self
    {
        $this->assertEditable();
        $unit = trim((string) $unit);
        $this->unit = '' === $unit ? null : $unit;

        return $this;
    }

    public function getHelpText(): ?string
    {
        return $this->helpText;
    }

    public function setHelpText(?string $helpText): self
    {
        $this->assertEditable();
        $helpText = trim((string) $helpText);
        $this->helpText = '' === $helpText ? null : $helpText;

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

    public function getLayoutColumns(): int
    {
        return $this->layoutColumns;
    }

    public function setLayoutColumns(int $layoutColumns): self
    {
        $this->assertEditable();
        if (! in_array($layoutColumns, [3, 6, 9, 12], true)) {
            throw new \InvalidArgumentException('The field width must be 25, 50, 75 or 100 percent.');
        }
        $this->layoutColumns = $layoutColumns;

        return $this;
    }

    public function isStartNewRow(): bool
    {
        return $this->startNewRow;
    }

    public function setStartNewRow(bool $startNewRow): self
    {
        $this->assertEditable();
        $this->startNewRow = $startNewRow;

        return $this;
    }

    public function isInputField(): bool
    {
        return ProtocolFieldType::StaticNote !== $this->type;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function setRequired(bool $required): self
    {
        $this->assertEditable();
        $this->required = $required && $this->isInputField();

        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function getOptions(): ?array
    {
        return $this->options;
    }

    /**
     * @param list<string>|null $options
     */
    public function setOptions(?array $options): self
    {
        $this->assertEditable();
        $this->options = null === $options ? null : array_values(array_unique(array_filter(array_map('trim', $options), static fn (string $option): bool => '' !== $option)));

        return $this;
    }

    private function assertEditable(): void
    {
        $this->section?->getRevision()?->assertEditable();
    }
}

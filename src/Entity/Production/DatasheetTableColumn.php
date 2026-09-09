<?php

declare(strict_types=1);

namespace App\Entity\Production;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'production_datasheet_table_columns')]
#[ORM\Index(name: 'IDX_PROD_DATASHEET_COLUMN_BLOCK', columns: ['block_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_PROD_DATASHEET_COLUMN_KEY', columns: ['block_id', 'stable_key'])]
#[ORM\UniqueConstraint(name: 'UNIQ_PROD_DATASHEET_COLUMN_POSITION', columns: ['block_id', 'position'])]
class DatasheetTableColumn extends AbstractProductionEntity
{
    #[ORM\ManyToOne(targetEntity: DatasheetTemplateBlock::class, inversedBy: 'columns')]
    #[ORM\JoinColumn(name: 'block_id', nullable: false, onDelete: 'CASCADE')]
    private ?DatasheetTemplateBlock $block = null;

    #[ORM\Column(name: 'stable_key', type: Types::STRING, length: 36)]
    private string $stableKey;

    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $label = '';

    #[ORM\Column(name: 'source_path', type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $sourcePath = '';

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    #[Assert\Length(max: 32)]
    private ?string $unit = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\PositiveOrZero]
    private int $position = 0;

    #[ORM\Column(type: Types::BOOLEAN, options: [
        'default' => false,
    ])]
    private bool $required = false;

    public function __construct(?string $stableKey = null)
    {
        $this->stableKey = $stableKey ?? Uuid::v7()->toRfc4122();
    }

    public function getBlock(): ?DatasheetTemplateBlock
    {
        return $this->block;
    }

    public function setBlock(DatasheetTemplateBlock $block): self
    {
        $block->getRevision()?->assertEditable();
        $this->block = $block;

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

    public function getSourcePath(): string
    {
        return $this->sourcePath;
    }

    public function setSourcePath(string $sourcePath): self
    {
        $this->assertEditable();
        $this->sourcePath = trim($sourcePath);

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

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function setRequired(bool $required): self
    {
        $this->assertEditable();
        $this->required = $required;

        return $this;
    }

    private function assertEditable(): void
    {
        $this->block?->getRevision()?->assertEditable();
    }
}

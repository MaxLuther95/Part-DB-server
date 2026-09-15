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
#[ORM\Table(name: 'production_datasheet_template_blocks')]
#[ORM\Index(name: 'IDX_PROD_DATASHEET_BLOCK_REV', columns: ['revision_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_PROD_DATASHEET_BLOCK_KEY', columns: ['revision_id', 'stable_key'])]
#[ORM\UniqueConstraint(name: 'UNIQ_PROD_DATASHEET_BLOCK_POSITION', columns: ['revision_id', 'position'])]
class DatasheetTemplateBlock extends AbstractProductionEntity
{
    #[ORM\ManyToOne(targetEntity: DatasheetTemplateRevision::class, inversedBy: 'blocks')]
    #[ORM\JoinColumn(name: 'revision_id', nullable: false, onDelete: 'CASCADE')]
    private ?DatasheetTemplateRevision $revision = null;

    #[ORM\Column(name: 'stable_key', type: Types::STRING, length: 36)]
    private string $stableKey;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: DatasheetBlockType::class)]
    private DatasheetBlockType $type = DatasheetBlockType::Value;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $label = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 10000)]
    private ?string $text = null;

    #[ORM\Column(name: 'source_path', type: Types::STRING, length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $sourcePath = null;

    // Null selects the automatic installation-slot label. Sources are relative
    // to each child, just like the data rows of a component table.
    #[ORM\Column(name: 'header_source_path', type: Types::STRING, length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $headerSourcePath = null;

    #[ORM\Column(name: 'header_format', type: Types::STRING, length: 255, options: ['default' => '{value}'])]
    private string $headerFormat = '{value}';

    public function getHeaderSourcePath(): ?string
    {
        return $this->headerSourcePath;
    }

    public function setHeaderSourcePath(?string $source): self
    {
        $this->assertEditable();
        $this->headerSourcePath = '' === trim((string) $source) ? null : trim((string) $source);

        return $this;
    }

    public function getHeaderFormat(): string
    {
        return $this->headerFormat;
    }

    public function setHeaderFormat(string $format): self
    {
        $this->assertEditable();
        $format = trim($format);
        if (mb_strlen($format) > 255 || substr_count($format, '{value}') !== 1
            || strpbrk(str_replace('{value}', '', $format), '{}') !== false) {
            throw new \DomainException('Die Spaltenüberschrift muss genau einmal {value} enthalten; weitere Platzhalter sind nicht erlaubt (höchstens 255 Zeichen).');
        }
        $this->headerFormat = $format;

        return $this;
    }

    #[ORM\Column(name: 'text_size', type: Types::STRING, length: 16, enumType: DatasheetTextSize::class, options: [
        'default' => 'normal',
    ])]
    private DatasheetTextSize $textSize = DatasheetTextSize::Normal;

    #[ORM\Column(name: 'font_family', type: Types::STRING, length: 16, enumType: DatasheetFontFamily::class, options: [
        'default' => 'sans_serif',
    ])]
    private DatasheetFontFamily $fontFamily = DatasheetFontFamily::SansSerif;

    #[ORM\Column(name: 'text_alignment', type: Types::STRING, length: 8, enumType: DatasheetTextAlignment::class, options: [
        'default' => 'left',
    ])]
    private DatasheetTextAlignment $textAlignment = DatasheetTextAlignment::Left;

    #[ORM\Column(name: 'text_bold', type: Types::BOOLEAN, options: [
        'default' => false,
    ])]
    private bool $textBold = false;

    #[ORM\Column(name: 'text_italic', type: Types::BOOLEAN, options: [
        'default' => false,
    ])]
    private bool $textItalic = false;

    #[ORM\Column(name: 'text_underlined', type: Types::BOOLEAN, options: [
        'default' => false,
    ])]
    private bool $textUnderlined = false;

    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\Range(min: 0, max: 100)]
    private int $position = 0;

    #[ORM\Column(name: 'layout_columns', type: Types::SMALLINT, options: [
        'default' => 12,
    ])]
    #[Assert\Choice(choices: [3, 6, 9, 12])]
    private int $layoutColumns = 12;

    #[ORM\Column(name: 'start_new_row', type: Types::BOOLEAN, options: [
        'default' => false,
    ])]
    private bool $startNewRow = false;

    #[ORM\Column(type: Types::BOOLEAN, options: [
        'default' => false,
    ])]
    private bool $required = false;

    #[ORM\Column(name: 'hide_if_empty', type: Types::BOOLEAN, options: [
        'default' => false,
    ])]
    private bool $hideIfEmpty = false;

    #[ORM\Column(name: 'minimum_rows', type: Types::INTEGER, options: [
        'default' => 0,
    ])]
    #[Assert\PositiveOrZero]
    private int $minimumRows = 0;

    #[ORM\Column(name: 'maximum_rows', type: Types::INTEGER, nullable: true)]
    #[Assert\Range(min: 1, max: 100)]
    private ?int $maximumRows = null;

    /**
     * @var Collection<int, DatasheetTableColumn>
     */
    #[ORM\OneToMany(mappedBy: 'block', targetEntity: DatasheetTableColumn::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy([
        'position' => 'ASC',
        'id' => 'ASC',
    ])]
    #[Assert\Count(max: 100, maxMessage: 'Eine Komponententabelle darf höchstens 100 Datenzeilen enthalten.')]
    private Collection $columns;

    public function __construct(?string $stableKey = null)
    {
        $this->stableKey = $stableKey ?? Uuid::v7()->toRfc4122();
        $this->columns = new ArrayCollection();
    }

    public function getRevision(): ?DatasheetTemplateRevision
    {
        return $this->revision;
    }

    public function setRevision(DatasheetTemplateRevision $revision): self
    {
        $revision->assertEditable();
        $this->revision = $revision;

        return $this;
    }

    public function getStableKey(): string
    {
        return $this->stableKey;
    }

    public function getType(): DatasheetBlockType
    {
        return $this->type;
    }

    public function setType(DatasheetBlockType $type): self
    {
        $this->assertEditable();
        $this->type = $type;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): self
    {
        $this->assertEditable();
        $label = trim((string) $label);
        $this->label = '' === $label ? null : $label;

        return $this;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function setText(?string $text): self
    {
        $this->assertEditable();
        $text = trim((string) $text);
        $this->text = '' === $text ? null : $text;

        return $this;
    }

    public function getSourcePath(): ?string
    {
        return $this->sourcePath;
    }

    public function setSourcePath(?string $sourcePath): self
    {
        $this->assertEditable();
        $sourcePath = trim((string) $sourcePath);
        $this->sourcePath = '' === $sourcePath ? null : $sourcePath;

        return $this;
    }

    public function getTextSize(): DatasheetTextSize
    {
        return $this->textSize;
    }

    public function setTextSize(DatasheetTextSize $textSize): self
    {
        $this->assertEditable();
        $this->textSize = $textSize;

        return $this;
    }

    public function getFontFamily(): DatasheetFontFamily
    {
        return $this->fontFamily;
    }

    public function setFontFamily(DatasheetFontFamily $fontFamily): self
    {
        $this->assertEditable();
        $this->fontFamily = $fontFamily;

        return $this;
    }

    public function getTextAlignment(): DatasheetTextAlignment
    {
        return $this->textAlignment;
    }

    public function setTextAlignment(DatasheetTextAlignment $textAlignment): self
    {
        $this->assertEditable();
        $this->textAlignment = $textAlignment;

        return $this;
    }

    public function isTextBold(): bool
    {
        return $this->textBold;
    }

    public function setTextBold(bool $textBold): self
    {
        $this->assertEditable();
        $this->textBold = $textBold;

        return $this;
    }

    public function isTextItalic(): bool
    {
        return $this->textItalic;
    }

    public function setTextItalic(bool $textItalic): self
    {
        $this->assertEditable();
        $this->textItalic = $textItalic;

        return $this;
    }

    public function isTextUnderlined(): bool
    {
        return $this->textUnderlined;
    }

    public function setTextUnderlined(bool $textUnderlined): self
    {
        $this->assertEditable();
        $this->textUnderlined = $textUnderlined;

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
            throw new \InvalidArgumentException('A datasheet block width must be 25, 50, 75 or 100 percent.');
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

    public function isHideIfEmpty(): bool
    {
        return $this->hideIfEmpty;
    }

    public function setHideIfEmpty(bool $hideIfEmpty): self
    {
        $this->assertEditable();
        $this->hideIfEmpty = $hideIfEmpty;

        return $this;
    }

    public function getMinimumRows(): int
    {
        return $this->minimumRows;
    }

    public function setMinimumRows(int $minimumRows): self
    {
        $this->assertEditable();
        $this->minimumRows = $minimumRows;

        return $this;
    }

    public function getMaximumRows(): ?int
    {
        return $this->maximumRows;
    }

    public function setMaximumRows(?int $maximumRows): self
    {
        $this->assertEditable();
        $this->maximumRows = $maximumRows;

        return $this;
    }

    /**
     * @return Collection<int, DatasheetTableColumn>
     */
    public function getColumns(): Collection
    {
        return $this->columns;
    }

    public function addColumn(DatasheetTableColumn $column): self
    {
        $this->assertEditable();
        if (! $this->columns->contains($column)) {
            $this->columns->add($column);
            $column->setBlock($this);
        }

        return $this;
    }

    public function removeColumn(DatasheetTableColumn $column): self
    {
        $this->assertEditable();
        $this->columns->removeElement($column);

        return $this;
    }

    private function assertEditable(): void
    {
        $this->revision?->assertEditable();
    }
}

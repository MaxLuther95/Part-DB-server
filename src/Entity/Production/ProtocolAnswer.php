<?php

declare(strict_types=1);

namespace App\Entity\Production;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'production_protocol_answers')]
#[ORM\Index(name: 'IDX_PROD_PROTOCOL_ANSWER_ROW', columns: ['row_id'])]
#[ORM\Index(name: 'IDX_PROD_PROTOCOL_ANSWER_FIELD', columns: ['field_id'])]
#[ORM\UniqueConstraint(name: 'UNIQ_PROD_PROTOCOL_ANSWER', columns: ['row_id', 'field_id'])]
class ProtocolAnswer extends AbstractProductionEntity
{
    #[ORM\ManyToOne(targetEntity: ProtocolRunSectionRow::class, inversedBy: 'answers')]
    #[ORM\JoinColumn(name: 'row_id', nullable: false, onDelete: 'CASCADE')]
    private ?ProtocolRunSectionRow $row = null;

    #[ORM\ManyToOne(targetEntity: ProtocolTemplateField::class)]
    #[ORM\JoinColumn(name: 'field_id', nullable: false)]
    private ?ProtocolTemplateField $field = null;

    #[ORM\Column(name: 'text_value', type: Types::TEXT, nullable: true)]
    private ?string $textValue = null;

    #[ORM\Column(name: 'integer_value', type: Types::INTEGER, nullable: true)]
    private ?int $integerValue = null;

    #[ORM\Column(name: 'decimal_value', type: Types::DECIMAL, precision: 24, scale: 9, nullable: true)]
    private ?string $decimalValue = null;

    #[ORM\Column(name: 'boolean_value', type: Types::BOOLEAN, nullable: true)]
    private ?bool $booleanValue = null;

    #[ORM\Column(name: 'date_value', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateValue = null;

    #[ORM\Column(name: 'datetime_value', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateTimeValue = null;

    public function getRow(): ?ProtocolRunSectionRow
    {
        return $this->row;
    }

    public function setRow(ProtocolRunSectionRow $row): self
    {
        $row->getRun()?->assertEditable();
        $this->row = $row;

        return $this;
    }

    public function getField(): ?ProtocolTemplateField
    {
        return $this->field;
    }

    public function setField(ProtocolTemplateField $field): self
    {
        if (! $field->isInputField()) {
            throw new \InvalidArgumentException('Static protocol content cannot have an answer.');
        }
        if (null !== $this->row && $this->row->getSection() !== $field->getSection()) {
            throw new \InvalidArgumentException('The answer field must belong to the row section.');
        }
        $this->row?->getRun()?->assertEditable();
        $this->field = $field;

        return $this;
    }

    public function getValue(): string|int|bool|\DateTimeImmutable|null
    {
        return match ($this->field?->getType()) {
            ProtocolFieldType::Integer => $this->integerValue,
            ProtocolFieldType::Decimal => $this->decimalValue,
            ProtocolFieldType::Boolean => $this->booleanValue,
            ProtocolFieldType::Date => $this->dateValue,
            ProtocolFieldType::DateTime => $this->dateTimeValue,
            default => $this->textValue,
        };
    }

    public function isEmpty(): bool
    {
        return null === $this->getValue() || '' === $this->getValue();
    }

    public function clearValue(): void
    {
        $this->assertEditable();
        $this->textValue = null;
        $this->integerValue = null;
        $this->decimalValue = null;
        $this->booleanValue = null;
        $this->dateValue = null;
        $this->dateTimeValue = null;
    }

    public function setValue(string|int|bool|\DateTimeImmutable|null $value): void
    {
        if (ProtocolFieldType::TestResult === $this->field?->getType()
            && null !== $value
            && '' !== $value
            && (! is_string($value) || ! in_array($value, ['pass', 'not_applicable', 'fail'], true))) {
            throw new \InvalidArgumentException('Unknown test result.');
        }

        $this->clearValue();
        if (null === $value || '' === $value) {
            return;
        }

        match ($this->field?->getType()) {
            ProtocolFieldType::Integer => $this->integerValue = (int) $value,
            ProtocolFieldType::Decimal => $this->decimalValue = (string) $value,
            ProtocolFieldType::Boolean => $this->booleanValue = (bool) $value,
            ProtocolFieldType::Date => $this->dateValue = $value instanceof \DateTimeImmutable ? $value->setTime(0, 0) : throw new \InvalidArgumentException('Expected a date.'),
            ProtocolFieldType::DateTime => $this->dateTimeValue = $value instanceof \DateTimeImmutable ? $value : throw new \InvalidArgumentException('Expected a date and time.'),
            ProtocolFieldType::TestResult => $this->textValue = (string) $value,
            default => $this->textValue = (string) $value,
        };
    }

    private function assertEditable(): void
    {
        $this->row?->getRun()?->assertEditable();
    }
}

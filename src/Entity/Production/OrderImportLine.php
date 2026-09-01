<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Repository\Production\OrderImportLineRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderImportLineRepository::class)]
#[ORM\Table(name: 'production_order_import_lines')]
class OrderImportLine extends AbstractProductionEntity
{
    #[ORM\ManyToOne(targetEntity: CustomerProject::class, inversedBy: 'importLines')]
    #[ORM\JoinColumn(name: 'customer_project_id', nullable: false, onDelete: 'CASCADE')]
    private ?CustomerProject $order = null;

    #[ORM\ManyToOne(targetEntity: OrderImportMapping::class)]
    #[ORM\JoinColumn(name: 'mapping_id', nullable: true, onDelete: 'SET NULL')]
    private ?OrderImportMapping $mapping = null;

    #[ORM\Column(type: Types::INTEGER)] private int $lineNumber = 0;
    #[ORM\Column(type: Types::STRING, length: 255)] private string $description = '';
    #[ORM\Column(type: Types::INTEGER)] private int $quantity = 1;
    #[ORM\Column(type: Types::STRING, length: 32)] private string $unit = OrderPositionUnit::Piece->value;

    public function getOrder(): ?CustomerProject { return $this->order; }
    public function setOrder(CustomerProject $order): self
    {
        if ($this->order === $order) {
            return $this;
        }
        $this->order?->removeImportLine($this);
        $this->order = $order;
        if (!$order->getImportLines()->contains($this)) {
            $order->addImportLine($this);
        }

        return $this;
    }
    public function getMapping(): ?OrderImportMapping { return $this->mapping; }
    public function setMapping(?OrderImportMapping $mapping): self { $this->mapping = $mapping; return $this; }
    public function getLineNumber(): int { return $this->lineNumber; }
    public function setLineNumber(int $lineNumber): self { $this->lineNumber = max(0, $lineNumber); return $this; }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): self { $this->description = trim($description); return $this; }
    public function getQuantity(): int { return $this->quantity; }
    public function setQuantity(int $quantity): self { $this->quantity = max(1, $quantity); return $this; }
    public function getUnit(): string { return $this->unit; }
    public function setUnit(string|OrderPositionUnit $unit): self
    {
        $normalized = $unit instanceof OrderPositionUnit ? $unit : OrderPositionUnit::fromImportedValue($unit);
        if (!$normalized instanceof OrderPositionUnit) {
            throw new \InvalidArgumentException('Unsupported order position unit.');
        }
        $this->unit = $normalized->value;

        return $this;
    }
}

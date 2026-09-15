<?php

declare(strict_types=1);

namespace App\Helpers\Production;

use App\Entity\Production\ManufacturingSnapshot;
use App\Entity\Production\SystemTemplateSlot;

final readonly class ManufacturingSlot
{
    /** @param array<string, mixed> $data */
    public function __construct(private ManufacturingSnapshot $snapshot, private array $data) {}
    public static function matches(SystemTemplateSlot|self|null $left, SystemTemplateSlot|self|null $right): bool
    {
        return $left === $right || (null !== $left?->getId() && $left->getId() === $right?->getId());
    }
    public function getId(): int { return $this->data['id']; }
    public function getName(): string { return $this->data['name']; }
    public function getPosition(): int { return $this->data['position']; }
    public function getMinQuantity(): int { return $this->data['min']; }
    public function getMaxQuantity(): int { return $this->data['max']; }
    public function isRequired(): bool { return $this->getMinQuantity() > 0; }
    public function isSerialTracking(): bool { return $this->data['serial']; }
    /** @return list<ManufacturingDefinition> */
    public function getChoices(): array
    {
        return array_map($this->snapshot->getDefinition(...), $this->data['choices']);
    }
    public function allows(string $key): bool { return in_array($key, $this->data['choices'], true); }
    /** @return list<ManufacturingDefinition> */
    public function getAllowedSystemTemplates(): array { return $this->choicesOfType('system'); }
    /** @return list<ManufacturingDefinition> */
    public function getAllowedProjects(): array { return $this->choicesOfType('project'); }
    /** @return list<ManufacturingDefinition> */
    public function getAllowedParts(): array { return $this->choicesOfType('part'); }
    /** @return list<ManufacturingDefinition> */
    private function choicesOfType(string $type): array
    {
        return array_values(array_filter($this->getChoices(), static fn(ManufacturingDefinition $choice): bool => $choice->getType() === $type));
    }
}

<?php

declare(strict_types=1);

namespace App\Helpers\Production;

use App\Entity\Production\ManufacturingSnapshot;
use App\Entity\ProjectSystem\Project;
use App\Entity\Parts\Part;

final class ManufacturingDefinition
{
    /** @var array<int, ManufacturingSlot> */
    private array $slots;

    /** @param array<string, mixed> $data */
    public function __construct(private readonly ManufacturingSnapshot $snapshot, private readonly string $key, private readonly array $data)
    {
        $this->slots = [];
        foreach ($data['slots'] as $slot) {
            $this->slots[$slot['id']] = new ManufacturingSlot($snapshot, $slot);
        }
    }

    public function getKey(): string { return $this->key; }
    public function getType(): string { return $this->data['type']; }
    public function getId(): int { return $this->data['id']; }
    public function getName(): string { return $this->data['name']; }
    public function getDescription(): string { return $this->data['description']; }
    public function getOrderUnit(): string { return $this->data['unit']; }
    /** @return array<int, ManufacturingSlot> */
    public function getSlots(): array { return $this->slots; }
    public function getSlot(int $id): ?ManufacturingSlot { return $this->slots[$id] ?? null; }
    /** @return list<array{part_id: int|null, name: string, quantity: float}> */
    public function getBom(): array { return array_map(static fn(array $row): array => [...$row, 'quantity' => (float) $row['quantity']], $this->data['bom']); }
    /** @return list<int> */
    public function getProjectIds(): array { return $this->data['project_ids']; }
    /** @return list<Project> */
    public function getBuildProjects(): array
    {
        return array_values(array_filter($this->snapshot->getProjects(), fn(Project $project): bool => in_array($project->getId(), $this->getProjectIds(), true)));
    }
    /** @return list<array{part: Part, quantity: float}> */
    public function getMaterialRows(): array
    {
        $rows = [];
        foreach ($this->getBom() as $row) {
            if (null === $row['part_id']) {
                continue; // A named non-part entry has no inventory to reserve or withdraw.
            }
            $part = $this->snapshot->getPart($row['part_id']);
            if (null === $part) { throw new \DomainException('Ein Bauteil des gespeicherten Fertigungsstands wurde gelöscht: '.$row['name']); }
            $rows[] = ['part' => $part, 'quantity' => $row['quantity']];
        }
        return $rows;
    }
    public function getFingerprint(): string { return hash('sha256', json_encode($this->data, JSON_THROW_ON_ERROR)); }
    public function getSnapshot(): ManufacturingSnapshot { return $this->snapshot; }
}

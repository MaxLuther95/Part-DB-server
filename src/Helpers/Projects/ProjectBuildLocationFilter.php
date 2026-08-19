<?php

declare(strict_types=1);

namespace App\Helpers\Projects;

use App\Entity\Parts\PartLot;
use App\Entity\Parts\StorageLocation;

/**
 * Non-persistent location filter used for a single project build.
 *
 * Explicit states override inherited states while walking from the root to
 * the lot's storage location. Locations without an explicit state inherit
 * from their closest explicitly configured ancestor or the global default.
 */
final readonly class ProjectBuildLocationFilter
{
    /**
     * @param array<int, bool> $explicitStates Storage location ID => allowed
     */
    public function __construct(
        private bool $defaultAllowed = true,
        private array $explicitStates = [],
        private ?bool $unassignedAllowed = false,
    ) {
        foreach ($this->explicitStates as $id => $state) {
            if (!is_int($id) || $id < 1 || !is_bool($state)) {
                throw new \InvalidArgumentException('Explicit location states must map positive integer IDs to booleans.');
            }
        }
    }

    public function isLotAllowed(PartLot $lot): bool
    {
        $location = $lot->getStorageLocation();

        if (!$location instanceof StorageLocation) {
            return $this->unassignedAllowed ?? $this->defaultAllowed;
        }

        return $this->isLocationAllowed($location);
    }

    public function isLocationAllowed(StorageLocation $location): bool
    {
        $allowed = $this->defaultAllowed;

        foreach ($location->getPathArray() as $pathElement) {
            $id = $pathElement->getID();
            if ($id !== null && array_key_exists($id, $this->explicitStates)) {
                $allowed = $this->explicitStates[$id];
            }
        }

        return $allowed;
    }

    public function isDefaultAllowed(): bool
    {
        return $this->defaultAllowed;
    }

    public function getExplicitState(int $locationId): ?bool
    {
        return $this->explicitStates[$locationId] ?? null;
    }

    /** @return array<int, bool> */
    public function getExplicitStates(): array
    {
        return $this->explicitStates;
    }

    public function getUnassignedAllowed(): ?bool
    {
        return $this->unassignedAllowed;
    }

    public function getExplicitStateCount(): int
    {
        return count($this->explicitStates) + ($this->unassignedAllowed === null ? 0 : 1);
    }

    /** @return array{default: string, unassigned: string, locations: array<int, string>} */
    public function toQueryParameters(): array
    {
        $locations = [];
        foreach ($this->explicitStates as $id => $allowed) {
            $locations[$id] = $allowed ? 'true' : 'false';
        }

        return [
            'default' => $this->defaultAllowed ? 'true' : 'false',
            'unassigned' => match ($this->unassignedAllowed) {
                true => 'true',
                false => 'false',
                null => 'indeterminate',
            },
            'locations' => $locations,
        ];
    }
}

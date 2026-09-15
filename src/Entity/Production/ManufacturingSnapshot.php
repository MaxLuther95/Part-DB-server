<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Helpers\Production\ManufacturingDefinition;
use App\Entity\Parts\Part;
use App\Entity\ProjectSystem\Project;
use Doctrine\Common\Collections\{ArrayCollection, Collection};
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** An immutable, order-owned graph; it contains definitions, never stock balances. */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'production_manufacturing_snapshots')]
class ManufacturingSnapshot extends AbstractProductionEntity
{
    /** @var array<string, array<string, mixed>> */
    #[ORM\Column(type: Types::JSON)]
    private array $definitions;

    /** @var Collection<int, Project> */
    #[ORM\ManyToMany(targetEntity: Project::class)]
    #[ORM\JoinTable(name: 'production_snapshot_projects')]
    #[ORM\JoinColumn(name: 'snapshot_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'project_id', onDelete: 'CASCADE')]
    private Collection $projects;

    /** @var Collection<int, Part> */
    #[ORM\ManyToMany(targetEntity: Part::class)]
    #[ORM\JoinTable(name: 'production_snapshot_parts')]
    #[ORM\JoinColumn(name: 'snapshot_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'part_id', onDelete: 'CASCADE')]
    private Collection $parts;

    /** @return list<Project> */
    public function getProjects(): array { return array_values($this->projects->toArray()); }
    /** @var array<int, Part>|null */
    private ?array $partIndex = null;
    public function getPart(int $id): ?Part
    {
        if (null === $this->partIndex) {
            $this->partIndex = [];
            foreach ($this->parts as $part) { $this->partIndex[$part->getId()] = $part; }
        }
        return $this->partIndex[$id] ?? null;
    }

    /** @var array<string, ManufacturingDefinition> */
    private array $views = [];

    /** @param array<string, array<string, mixed>> $definitions @param list<Project> $projects @param list<Part> $parts */
    public function __construct(array $definitions, array $projects = [], array $parts = [])
    {
        $this->definitions = $definitions;
        $this->projects = new ArrayCollection($projects);
        $this->parts = new ArrayCollection($parts);
    }

    /** @return array<string, array<string, mixed>> */
    public function getDefinitions(): array { return $this->definitions; }
    /** @return list<Part> */
    public function getParts(): array { return array_values($this->parts->toArray()); }

    public function has(string $key): bool { return isset($this->definitions[$key]); }

    public function getDefinition(string $key): ManufacturingDefinition
    {
        if (!$this->has($key)) {
            throw new \DomainException('Der Inhalt gehört nicht zum gespeicherten Fertigungsstand.');
        }

        return $this->views[$key] ??= new ManufacturingDefinition($this, $key, $this->definitions[$key]);
    }
}

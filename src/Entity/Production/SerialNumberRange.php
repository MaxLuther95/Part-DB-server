<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\ProjectSystem\Project;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'production_serial_number_ranges')]
#[UniqueEntity(fields: ['prefix'])]
class SerialNumberRange extends AbstractProductionEntity
{
    #[ORM\Column(length: 128)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 128)]
    private string $name = '';

    #[ORM\Column(length: 4, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^[A-Z0-9]{3,4}$/D')]
    private string $prefix = '';

    #[ORM\Column]
    #[Assert\Range(min: 1, max: 1000000000)]
    private int $nextNumber = 1;

    #[ORM\Column]
    #[Assert\Range(min: 1, max: 9)]
    private int $minimumDigits = 4;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    /**
     * @var Collection<int, SystemTemplate>
     */
    #[ORM\ManyToMany(targetEntity: SystemTemplate::class)]
    #[ORM\JoinTable(name: 'production_serial_range_systems')]
    #[ORM\JoinColumn(name: 'range_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'system_template_id', unique: true, onDelete: 'CASCADE')]
    private Collection $systems;

    /**
     * @var Collection<int, Project>
     */
    #[ORM\ManyToMany(targetEntity: Project::class)]
    #[ORM\JoinTable(name: 'production_serial_range_projects')]
    #[ORM\JoinColumn(name: 'range_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'project_id', unique: true, onDelete: 'CASCADE')]
    private Collection $projects;

    public function __construct()
    {
        $this->systems = new ArrayCollection();
        $this->projects = new ArrayCollection();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $value): self
    {
        $this->name = trim($value);

        return $this;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function setPrefix(string $value): self
    {
        $this->prefix = strtoupper(trim($value));

        return $this;
    }

    public function getNextNumber(): int
    {
        return $this->nextNumber;
    }

    public function setNextNumber(int $value): self
    {
        $this->nextNumber = $value;

        return $this;
    }

    public function getMinimumDigits(): int
    {
        return $this->minimumDigits;
    }

    public function setMinimumDigits(int $value): self
    {
        $this->minimumDigits = $value;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * @return Collection<int, SystemTemplate>
     */
    public function getSystems(): Collection
    {
        return $this->systems;
    }

    public function addSystem(SystemTemplate $value): self
    {
        if (! $this->systems->contains($value)) {
            $this->systems->add($value);
        }

return $this;
    }

    public function removeSystem(SystemTemplate $value): self
    {
        $this->systems->removeElement($value);

        return $this;
    }

    /**
     * @return Collection<int, Project>
     */
    public function getProjects(): Collection
    {
        return $this->projects;
    }

    public function addProject(Project $value): self
    {
        if (! $this->projects->contains($value)) {
            $this->projects->add($value);
        }

return $this;
    }

    public function removeProject(Project $value): self
    {
        $this->projects->removeElement($value);

        return $this;
    }
}

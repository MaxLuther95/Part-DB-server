<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\ProjectSystem\Project;
use App\Repository\Production\SystemTemplateRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SystemTemplateRepository::class)]
#[ORM\Table(name: 'production_system_templates')]
class SystemTemplate extends AbstractProductionEntity
{
    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    /** @var Collection<int, Project> */
    #[ORM\ManyToMany(targetEntity: Project::class)]
    #[ORM\JoinTable(name: 'production_system_template_base_projects')]
    #[ORM\JoinColumn(name: 'system_template_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'project_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $baseProjects;

    #[ORM\Column(type: Types::TEXT)]
    private string $description = '';

    #[ORM\Column(name: 'order_unit', type: Types::STRING, length: 16, enumType: OrderPositionUnit::class, options: ['default' => 'pcs.'])]
    private OrderPositionUnit $orderUnit = OrderPositionUnit::Piece;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    /** @var Collection<int, SystemTemplateSlot> */
    #[ORM\OneToMany(mappedBy: 'systemTemplate', targetEntity: SystemTemplateSlot::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'name' => 'ASC'])]
    private Collection $slots;

    /** @var Collection<int, SystemTemplateSlot> */
    #[ORM\ManyToMany(targetEntity: SystemTemplateSlot::class, mappedBy: 'allowedSystemTemplates')]
    private Collection $usedInSlots;

    public function __construct()
    {
        $this->baseProjects = new ArrayCollection();
        $this->slots = new ArrayCollection();
        $this->usedInSlots = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = trim($name);

        return $this;
    }

    public function getBaseProject(): ?Project
    {
        $first = $this->baseProjects->first();

        return false === $first ? null : $first;
    }

    public function setBaseProject(?Project $baseProject): self
    {
        $this->baseProjects->clear();
        if (null !== $baseProject) {
            $this->baseProjects->add($baseProject);
        }

        return $this;
    }

    /** @return Collection<int, Project> */
    public function getBaseProjects(): Collection
    {
        return $this->baseProjects;
    }

    public function addBaseProject(Project $project): self
    {
        if (!$this->baseProjects->contains($project)) {
            $this->baseProjects->add($project);
        }

        return $this;
    }

    public function removeBaseProject(Project $project): self
    {
        $this->baseProjects->removeElement($project);

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getOrderUnit(): OrderPositionUnit
    {
        return $this->orderUnit;
    }

    public function setOrderUnit(OrderPositionUnit $orderUnit): self
    {
        $this->orderUnit = $orderUnit;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    /** @return Collection<int, SystemTemplateSlot> */
    public function getSlots(): Collection
    {
        return $this->slots;
    }

    public function addSlot(SystemTemplateSlot $slot): self
    {
        if (!$this->slots->contains($slot)) {
            $this->slots->add($slot);
            $slot->setSystemTemplate($this);
        }

        return $this;
    }

    public function removeSlot(SystemTemplateSlot $slot): self
    {
        if ($this->slots->removeElement($slot) && $slot->getSystemTemplate() === $this) {
            $slot->setSystemTemplate(null);
        }

        return $this;
    }

    /** @return Collection<int, SystemTemplateSlot> */
    public function getUsedInSlots(): Collection
    {
        return $this->usedInSlots;
    }

    public function addUsedInSlot(SystemTemplateSlot $slot): self
    {
        if (!$this->usedInSlots->contains($slot)) {
            $this->usedInSlots->add($slot);
        }

        return $this;
    }

    public function removeUsedInSlot(SystemTemplateSlot $slot): self
    {
        $this->usedInSlots->removeElement($slot);

        return $this;
    }

    /** @return list<SystemTemplate> */
    public function getParentTemplates(): array
    {
        $parents = [];
        foreach ($this->usedInSlots as $slot) {
            $parent = $slot->getSystemTemplate();
            if (null !== $parent && $parent !== $this) {
                $parents[$parent->getId() ?? spl_object_id($parent)] = $parent;
            }
        }
        uasort($parents, static fn(self $left, self $right): int => strcasecmp($left->getName(), $right->getName()));

        return array_values($parents);
    }
}

<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\ProjectSystem\Project;
use App\Repository\Production\ProtocolTemplateRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProtocolTemplateRepository::class)]
#[ORM\Table(name: 'production_protocol_templates')]
class ProtocolTemplate extends AbstractProductionEntity
{
    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $description = '';

    #[ORM\Column(type: Types::BOOLEAN, options: [
        'default' => true,
    ])]
    private bool $active = true;

    /** @var Collection<int, SystemTemplate> */
    #[ORM\ManyToMany(targetEntity: SystemTemplate::class)]
    #[ORM\JoinTable(name: 'production_protocol_template_systems')]
    #[ORM\JoinColumn(name: 'protocol_template_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'system_template_id', referencedColumnName: 'id', unique: true, onDelete: 'CASCADE')]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $systemTemplates;

    /** @var Collection<int, Project> */
    #[ORM\ManyToMany(targetEntity: Project::class)]
    #[ORM\JoinTable(name: 'production_protocol_template_projects')]
    #[ORM\JoinColumn(name: 'protocol_template_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'project_id', referencedColumnName: 'id', unique: true, onDelete: 'CASCADE')]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $projects;

    /**
     * @var Collection<int, ProtocolTemplateRevision>
     */
    #[ORM\OneToMany(mappedBy: 'template', targetEntity: ProtocolTemplateRevision::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy([
        'revisionNumber' => 'DESC',
    ])]
    private Collection $revisions;

    public function __construct()
    {
        $this->revisions = new ArrayCollection();
        $this->systemTemplates = new ArrayCollection();
        $this->projects = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return Collection<int, SystemTemplate> */
    public function getSystemTemplates(): Collection
    {
        return $this->systemTemplates;
    }

    public function addSystemTemplate(SystemTemplate $system): self
    {
        if (! $this->systemTemplates->contains($system)) {
            $this->systemTemplates->add($system);
        }

        return $this;
    }

    public function removeSystemTemplate(SystemTemplate $system): self
    {
        $this->systemTemplates->removeElement($system);

        return $this;
    }

    public function setName(string $name): self
    {
        $this->name = trim($name);

        return $this;
    }

    /** @return Collection<int, Project> */
    public function getProjects(): Collection
    {
        return $this->projects;
    }

    public function addProject(Project $project): self
    {
        if (! $this->projects->contains($project)) {
            $this->projects->add($project);
        }

        return $this;
    }

    public function removeProject(Project $project): self
    {
        $this->projects->removeElement($project);

        return $this;
    }

    public function appliesTo(BuildInstance $instance): bool
    {
        // A system never inherits its components' or base project's assignment.
        if (null !== $instance->getSystemTemplate()) {
            return $this->systemTemplates->contains($instance->getSystemTemplate());
        }

        return null !== $instance->getTemplateProject() && $this->projects->contains($instance->getTemplateProject());
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = trim((string) $description);

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

    /**
     * @return Collection<int, ProtocolTemplateRevision>
     */
    public function getRevisions(): Collection
    {
        return $this->revisions;
    }

    public function addRevision(ProtocolTemplateRevision $revision): self
    {
        if (! $this->revisions->contains($revision)) {
            $this->revisions->add($revision);
            $revision->setTemplate($this);
        }

        return $this;
    }

    public function getDraftRevision(): ?ProtocolTemplateRevision
    {
        foreach ($this->revisions as $revision) {
            if (ProtocolRevisionStatus::Draft === $revision->getStatus()) {
                return $revision;
            }
        }

        return null;
    }

    public function getPublishedRevision(): ?ProtocolTemplateRevision
    {
        foreach ($this->revisions as $revision) {
            if (ProtocolRevisionStatus::Published === $revision->getStatus()) {
                return $revision;
            }
        }

        return null;
    }

    public function getNextRevisionNumber(): int
    {
        $highest = 0;
        foreach ($this->revisions as $revision) {
            $highest = max($highest, $revision->getRevisionNumber());
        }

        return $highest + 1;
    }
}

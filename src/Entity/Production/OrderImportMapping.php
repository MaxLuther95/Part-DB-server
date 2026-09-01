<?php

declare(strict_types=1);

namespace App\Entity\Production;

use App\Entity\Parts\Part;
use App\Entity\ProjectSystem\Project;
use App\Repository\Production\OrderImportMappingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: OrderImportMappingRepository::class)]
#[ORM\Table(name: 'production_order_import_mappings')]
#[UniqueEntity(fields: ['normalizedDescription'], message: 'Diese Beschreibung ist bereits zugeordnet.')]
class OrderImportMapping extends AbstractProductionEntity
{
    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $sourceDescription = '';

    #[ORM\Column(type: Types::STRING, length: 255, unique: true)]
    private string $normalizedDescription = '';

    #[ORM\ManyToOne(targetEntity: SystemTemplate::class)]
    #[ORM\JoinColumn(name: 'system_template_id', nullable: true, onDelete: 'CASCADE')]
    private ?SystemTemplate $systemTemplate = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'template_project_id', nullable: true, onDelete: 'CASCADE')]
    private ?Project $templateProject = null;

    #[ORM\ManyToOne(targetEntity: Part::class)]
    #[ORM\JoinColumn(name: 'part_id', nullable: true, onDelete: 'CASCADE')]
    private ?Part $part = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    public function getSourceDescription(): string { return $this->sourceDescription; }
    public function setSourceDescription(string $description): self
    {
        $this->sourceDescription = trim($description);
        $this->normalizedDescription = self::normalize($description);
        return $this;
    }
    public function getNormalizedDescription(): string { return $this->normalizedDescription; }
    public function getSystemTemplate(): ?SystemTemplate { return $this->systemTemplate; }
    public function setSystemTemplate(?SystemTemplate $template): self { $this->systemTemplate = $template; return $this; }
    public function getTemplateProject(): ?Project { return $this->templateProject; }
    public function setTemplateProject(?Project $project): self { $this->templateProject = $project; return $this; }
    public function getPart(): ?Part { return $this->part; }
    public function setPart(?Part $part): self { $this->part = $part; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): self { $this->active = $active; return $this; }

    public function getTargetLabel(): string
    {
        return $this->systemTemplate?->getName() ?? $this->templateProject?->getName() ?? $this->part?->getName() ?? '–';
    }

    public function getTargetType(): string
    {
        return null !== $this->systemTemplate ? 'Systemvorlage' : (null !== $this->templateProject ? 'Bauprojekt' : (null !== $this->part ? 'Lagerteil' : '–'));
    }

    public function getOrderUnit(): OrderPositionUnit
    {
        return $this->systemTemplate?->getOrderUnit() ?? OrderPositionUnit::Piece;
    }

    #[Assert\Callback]
    public function validateTarget(ExecutionContextInterface $context): void
    {
        $count = (int) (null !== $this->systemTemplate) + (int) (null !== $this->templateProject) + (int) (null !== $this->part);
        if (1 !== $count) {
            $context->buildViolation('Bitte genau ein Ziel auswählen: Systemvorlage, Bauprojekt oder Lagerteil.')->addViolation();
        }
    }

    public static function normalize(string $description): string
    {
        $description = mb_strtolower(trim($description));
        return preg_replace('/\s+/u', ' ', $description) ?? $description;
    }
}

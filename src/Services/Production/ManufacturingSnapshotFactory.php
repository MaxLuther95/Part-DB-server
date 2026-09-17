<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Entity\Parts\Part;
use App\Entity\Production\{ManufacturingSnapshot, ProjectPosition, SystemTemplate};
use App\Entity\ProjectSystem\Project;
use App\Helpers\Production\ManufacturingSlot;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ManufacturingSnapshotFactory
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public static function key(SystemTemplate|Project|Part $content): string
    {
        return ($content instanceof SystemTemplate ? 'system' : ($content instanceof Project ? 'project' : 'part')).'_'.$content->getId();
    }

    public function capture(SystemTemplate|Project $content): ManufacturingSnapshot
    {
        $nodes = [];
        $references = [];
        $this->collect($content, $nodes, [], $references);
        if (strlen(json_encode($nodes, JSON_THROW_ON_ERROR)) > 8 * 1024 * 1024) {
            throw new \DomainException('Der Fertigungsaufbau überschreitet die zulässige Größe.');
        }

        return new ManufacturingSnapshot($nodes, array_values(array_filter($references, static fn(object $item): bool => $item instanceof Project)), array_values(array_filter($references, static fn(object $item): bool => $item instanceof Part)));
    }

    public function initialize(ProjectPosition $position): void
    {
        if (null !== $position->getManufacturingSnapshot()) { return; }
        $content = $position->getSystemTemplate() ?? $position->getTemplateProject();
        if (null === $content) { return; } // A historical, deleted source cannot be reconstructed.
        $parent = $position->getParent();
        if (null !== $parent) { $this->initialize($parent); }
        $key = self::key($content);
        $snapshot = $parent?->getManufacturingSnapshot() ?? $this->capture($content);
        $position->setManufacturingSnapshot($snapshot, $key);
    }

    /** @return list<SystemTemplate|Project|Part> */
    public function choices(ManufacturingSlot $slot): array
    {
        $choices = [];
        foreach ($slot->getChoices() as $definition) {
            $class = match ($definition->getType()) { 'system' => SystemTemplate::class, 'project' => Project::class, 'part' => Part::class, default => throw new \DomainException('Unbekannter Inhalt im Fertigungsstand.') };
            $entity = $this->entityManager->find($class, $definition->getId());
            if (null !== $entity) { $choices[] = $entity; }
        }

        return $choices;
    }

    /** @param array<string, array<string, mixed>> $nodes @param array<string, true> $path */
    private function collect(SystemTemplate|Project|Part $content, array &$nodes, array $path, array &$references): void
    {
        if (null === $content->getId()) {
            throw new \DomainException('Vorlagen und Bauteile müssen vor der Auftragszuordnung gespeichert sein.');
        }
        $key = self::key($content);
        $references[$key] = $content;
        if (isset($path[$key])) { throw new \DomainException('Der Fertigungsaufbau enthält eine zyklische Baugruppenreferenz.'); }
        if (isset($nodes[$key])) { return; }
        if (count($nodes) >= 10000 || count($path) >= 64) { throw new \DomainException('Der Fertigungsaufbau ist zu groß oder zu tief verschachtelt.'); }
        $path[$key] = true;
        $node = ['type' => explode('_', $key)[0], 'id' => $content->getId(), 'name' => $content->getName(), 'description' => $content instanceof SystemTemplate ? $content->getDescription() : '', 'unit' => $content instanceof SystemTemplate ? $content->getOrderUnit()->value : 'pcs.', 'project_ids' => [], 'bom' => [], 'slots' => []];
        foreach ($content instanceof Project ? [$content] : ($content instanceof SystemTemplate ? $content->getBaseProjects() : []) as $project) {
            if (null === $project->getId()) { throw new \DomainException('Bauprojekte müssen vor der Auftragszuordnung gespeichert sein.'); }
            $node['project_ids'][] = $project->getId();
            $references['project_'.$project->getId()] = $project;
            foreach ($project->getBomEntries() as $entry) {
                $part = $entry->getPart();
                if (null !== $part) {
                    if (null === $part->getId()) {
                        throw new \DomainException('Stücklistenbauteile müssen vor der Auftragszuordnung gespeichert sein.');
                    }
                    $references['part_'.$part->getId()] = $part;
                } elseif (null === $entry->getName()) {
                    throw new \DomainException('Eine Stücklistenposition hat weder ein Bauteil noch eine Bezeichnung.');
                }
                // Native Part-DB also supports named non-part entries (e.g. services).
                // Keep these in the frozen definition without inventing a stock part.
                $node['bom'][] = [
                    'part_id' => $part?->getId(),
                    'name' => $part?->getName() ?? $entry->getName(),
                    'quantity' => (float) $entry->getQuantity(),
                ];
            }
        }
        $nodes[$key] = $node;
        if (!$content instanceof SystemTemplate) { return; }
        foreach ($content->getSlots() as $slot) {
            if (null === $slot->getId()) { throw new \DomainException('Vorlagenänderungen müssen vor der Auftragszuordnung gespeichert sein.'); }
            $choices = [];
            foreach ([...$slot->getAllowedSystemTemplates(), ...$slot->getAllowedProjects(), ...$slot->getAllowedParts()] as $choice) {
                $this->collect($choice, $nodes, $path, $references);
                $choices[] = self::key($choice);
            }
            $nodes[$key]['slots'][] = ['id' => $slot->getId(), 'name' => $slot->getName(), 'position' => $slot->getPosition(), 'min' => $slot->getMinQuantity(), 'max' => $slot->getMaxQuantity(), 'serial' => $slot->isSerialTracking(), 'choices' => $choices];
        }
    }
}

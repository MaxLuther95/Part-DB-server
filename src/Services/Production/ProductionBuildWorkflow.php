<?php

declare(strict_types=1);

namespace App\Services\Production;

use App\Entity\Parts\Part;
use App\Entity\Parts\PartLot;
use App\Entity\Parts\StorageLocation;
use App\Entity\Production\BuildInstance;
use App\Entity\Production\BuildMaterialUsage;
use App\Entity\Production\BuildStatus;
use App\Entity\Production\CustomerProject;
use App\Entity\Production\ProjectAccessory;
use App\Entity\Production\ProjectMaterialAllocation;
use App\Entity\Production\ProjectMaterialReservation;
use App\Entity\Production\ProjectPosition;
use App\Entity\Production\SystemTemplate;
use App\Entity\Production\SystemTemplateSlot;
use App\Entity\ProjectSystem\Project;
use App\Entity\UserSystem\User;
use App\Services\Parts\PartLotWithdrawAddHelper;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\Production\ProjectMaterialReservationRepository;

final readonly class ProductionBuildWorkflow
{
    public const DRAFT_VERSION = 2;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PartLotWithdrawAddHelper $withdrawHelper,
        private ProductionHistoryRecorder $historyRecorder,
        private BuildConfigurationCompatibility $configurationCompatibility,
        private ProjectMaterialReservationRepository $reservationRepository,
        private ProductionReservationManager $reservationManager,
    ) {
    }

    /** @return array<string, mixed> */
    public function createDraft(SystemTemplate|Project $content, ?ProjectPosition $position = null): array
    {
        $draft = ['version' => self::DRAFT_VERSION, 'root' => 'n0', 'position_id' => $position?->getId(), 'nodes' => [], 'site_id' => $position?->getCustomerProject() instanceof CustomerProject ? $this->reservationManager->getPreferredSite($position->getCustomerProject())?->getId() : null, 'details' => [], 'lots' => [], 'materials_taken' => []];
        if (null !== $position) {
            $this->appendPositionNode($draft, $position, null);
        } else {
            // A free build creates exactly one top-level device. For a system
            // template its base projects supply the BOM, but no slot content is built.
            $this->appendContentNode($draft, $content, null, false);
        }

        return $draft;
    }

    /** @param array<string, mixed> $draft */
    public function getNextUnconfiguredNode(array $draft): ?string
    {
        foreach ($draft['nodes'] as $key => $node) {
            if ('system' === $node['type'] && false === $node['configured']) {
                return (string) $key;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $draft */
    public function resolveNode(array $draft, string $key): array
    {
        $node = $draft['nodes'][$key] ?? null;
        if (!is_array($node)) {
            throw new \InvalidArgumentException('Unknown build node.');
        }
        $content = 'system' === $node['type']
            ? $this->entityManager->find(SystemTemplate::class, $node['content_id'])
            : $this->entityManager->find(Project::class, $node['content_id']);
        if (!$content instanceof SystemTemplate && !$content instanceof Project) {
            throw new \RuntimeException('The selected build content no longer exists.');
        }

        return ['data' => $node, 'content' => $content];
    }

    /**
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $submitted
     * @return list<string>
     */
    public function configureNode(array &$draft, string $nodeKey, array $submitted): array
    {
        $resolved = $this->resolveNode($draft, $nodeKey);
        $template = $resolved['content'];
        if (!$template instanceof SystemTemplate) {
            return ['Die gewählte Position ist keine Systemvorlage.'];
        }
        $errors = [];
        $pending = [];
        foreach ($template->getSlots() as $slot) {
            $slotId = (string) $slot->getId();
            $choice = trim((string) ($submitted['choice'][$slotId] ?? ''));
            if (1 === $slot->getMaxQuantity()) {
                $quantity = '' === $choice ? 0 : 1;
            } else {
                $quantity = filter_var($submitted['quantity'][$slotId] ?? 0, FILTER_VALIDATE_INT);
                $quantity = false === $quantity ? -1 : $quantity;
                if ('' === $choice) {
                    $quantity = 0;
                }
            }
            if ($quantity < $slot->getMinQuantity() || $quantity > $slot->getMaxQuantity()) {
                $errors[] = sprintf('%s: Anzahl muss zwischen %d und %d liegen.', $slot->getName(), $slot->getMinQuantity(), $slot->getMaxQuantity());
                continue;
            }
            if (0 === $quantity) {
                $pending[] = [$slot, null, 0];
                continue;
            }
            $content = $this->resolveAllowedChoice($slot, $choice);
            if (null === $content) {
                $errors[] = sprintf('%s: Der gewählte Inhalt ist nicht erlaubt.', $slot->getName());
                continue;
            }
            $pending[] = [$slot, $content, $quantity];
        }
        if ([] !== $errors) {
            return $errors;
        }
        foreach ($pending as [$slot, $content, $quantity]) {
            if ($content instanceof Part) {
                $draft['nodes'][$nodeKey]['parts'][] = ['part_id' => $content->getId(), 'quantity' => $quantity, 'slot' => $slot->getName(), 'slot_id' => $slot->getId()];
                continue;
            }
            for ($i = 0; $i < $quantity; ++$i) {
                $this->appendContentNode($draft, $content, $nodeKey, true, $quantity > 1 ? sprintf('%s %d', $slot->getName(), $i + 1) : $slot->getName(), null, $slot->getId(), $i);
            }
        }
        $draft['nodes'][$nodeKey]['configured'] = true;

        return [];
    }

    /** @return list<array{value: string, label: string}> */
    public function getChoices(SystemTemplateSlot $slot): array
    {
        $choices = [];
        foreach ($slot->getAllowedSystemTemplates() as $template) {
            $choices[] = ['value' => 'system_'.$template->getId(), 'label' => $template->getName().' (Systemvorlage)'];
        }
        foreach ($slot->getAllowedProjects() as $project) {
            $choices[] = ['value' => 'project_'.$project->getId(), 'label' => $project->getFullPath().' (Bauprojekt)'];
        }
        foreach ($slot->getAllowedParts() as $part) {
            $choices[] = ['value' => 'part_'.$part->getId(), 'label' => $part->getName().' (Lagerteil)'];
        }

        return $choices;
    }

    /**
     * @param array<string, mixed> $draft
     * @return array{items: list<array<string, mixed>>, complete: bool}
     */
    public function createMaterialPlan(array $draft, ?StorageLocation $site): array
    {
        $requirements = $this->requirements($draft);
        $project = $this->getCustomerProject($draft);
        $items = [];
        $complete = true;
        foreach ($requirements as $partId => $requirement) {
            $part = $this->entityManager->find(Part::class, $partId);
            if (!$part instanceof Part) {
                continue;
            }
            $projectStock = 0;
            if (null !== $project) {
                foreach ($project->getMaterialAllocations() as $allocation) {
                    if ($allocation->getPart()?->getId() === $partId) {
                        $projectStock += $allocation->getQuantity();
                    }
                }
            }
            $fromProject = min($requirement['quantity'], $projectStock);
            $remainingAfterProjectStock = $requirement['quantity'] - $fromProject;
            $reservedSources = [];
            $fromReservation = 0;
            if (null !== $project && null !== $site) {
                foreach ($project->getMaterialReservations() as $reservation) {
                    $lot = $reservation->getSourcePartLot();
                    if ($fromReservation >= $remainingAfterProjectStock
                        || $reservation->getPart()?->getId() !== $partId
                        || !$lot instanceof PartLot
                        || !$this->lotBelongsToSite($lot, $site)) {
                        continue;
                    }
                    $availableAgainstOthers = max(0, (int) floor($lot->getAmount()) - $this->reservationRepository->quantityForLot($lot, $project));
                    $take = min($reservation->getQuantity(), $availableAgainstOthers, $remainingAfterProjectStock - $fromReservation);
                    if ($take > 0) {
                        $reservedSources[] = ['reservation' => $reservation, 'lot' => $lot, 'quantity' => $take];
                        $fromReservation += $take;
                    }
                }
            }
            $remaining = $remainingAfterProjectStock - $fromReservation;
            $lots = [];
            $available = 0;
            if (null !== $site) {
                foreach ($part->getPartLots() as $lot) {
                    if (!$lot->isInstockUnknown() && $lot->getAmount() > 0 && $this->lotBelongsToSite($lot, $site)) {
                        $amount = max(0, (int) floor($lot->getAmount()) - $this->reservationRepository->quantityForLot($lot));
                        if ($amount > 0) {
                            $lots[] = ['lot' => $lot, 'available' => $amount];
                            $available += $amount;
                        }
                    }
                }
            }
            $complete = $complete && $available >= $remaining;
            $items[] = ['part' => $part, 'required' => $requirement['quantity'], 'project_stock' => $fromProject, 'project_reserved' => $fromReservation, 'reserved_sources' => $reservedSources, 'remaining' => $remaining, 'lots' => $lots, 'available' => $available, 'missing' => max(0, $remaining - $available), 'contributions' => $requirement['contributions']];
        }
        usort($items, static fn(array $a, array $b): int => strcasecmp($a['part']->getName(), $b['part']->getName()));

        return ['items' => $items, 'complete' => $complete];
    }

    /** @param array<string, mixed> $draft */
    public function finalize(array $draft, User $user): BuildInstance
    {
        $site = $this->entityManager->find(StorageLocation::class, $draft['site_id']);
        if (!$site instanceof StorageLocation) {
            throw new \RuntimeException('Der Fertigungsstandort ist nicht mehr vorhanden.');
        }
        $plan = $this->createMaterialPlan($draft, $site);
        if (!$plan['complete']) {
            throw new \RuntimeException('Am gewählten Standort ist nicht genügend Material verfügbar.');
        }
        $instances = [];
        return $this->entityManager->wrapInTransaction(function () use ($draft, $user, $site, $plan, &$instances): BuildInstance {
            foreach ($draft['nodes'] as $key => $node) {
                $resolved = $this->resolveNode($draft, (string) $key);
                $details = $draft['details'][$key] ?? [];
                $serial = trim((string) ($details['serial'] ?? ''));
                $notes = trim((string) ($details['notes'] ?? ''));
                $status = BuildStatus::tryFrom((string) ($details['status'] ?? BuildStatus::InProgress->value));
                if ('' === $serial && '' === $notes) {
                    throw new \RuntimeException(sprintf('Für %s muss ohne Seriennummer ein Grund angegeben werden.', $node['name']));
                }
                if (!$status instanceof BuildStatus) {
                    throw new \RuntimeException(sprintf('Für %s wurde ein ungültiger Status gewählt.', $node['name']));
                }
                $instance = (new BuildInstance())
                    ->setSerialNumber('' === $serial ? null : $serial)
                    ->setLocation($site->getFullPath());
                $instance->setNotes('' === $notes ? null : $notes)->setStatus($status);
                $resolved['content'] instanceof SystemTemplate ? $instance->setSystemTemplate($resolved['content']) : $instance->setTemplateProject($resolved['content']);
                if (null !== $node['position_id']) {
                    $position = $this->entityManager->find(ProjectPosition::class, $node['position_id']);
                    if (!$position instanceof ProjectPosition || !$position->getBuildInstances()->isEmpty()) {
                        throw new \RuntimeException(sprintf('Die Projektposition %s ist bereits belegt oder nicht mehr vorhanden.', $node['name']));
                    }
                    $instance->setProjectPosition($position);
                    if (!$this->configurationCompatibility->synchronizePhysicalRelations($instance, $position)) {
                        throw new \RuntimeException(sprintf('Die Projektposition %s kann nicht widerspruchsfrei zugewiesen werden.', $node['name']));
                    }
                }
                if (null !== $node['parent']) {
                    $instance->setParent($instances[$node['parent']]);
                    $installedSlotId = filter_var($node['source_slot_id'] ?? null, FILTER_VALIDATE_INT);
                    $installedSlot = false === $installedSlotId ? null : $this->entityManager->find(SystemTemplateSlot::class, $installedSlotId);
                    if (!$installedSlot instanceof SystemTemplateSlot) {
                        throw new \RuntimeException(sprintf('Für %s ist kein gültiger Steckplatz gespeichert.', $node['name']));
                    }
                    $instance->setInstalledSlot($installedSlot)->setInstalledSlotIndex((int) ($node['slot_index'] ?? 0));
                }
                $this->entityManager->persist($instance);
                $instances[$key] = $instance;
            }
            $root = $instances[$draft['root']];
            $reservationConsumed = $this->consumeMaterials($draft, $plan, $instances, $root, $user);
            if ($root->getCustomerProject() instanceof CustomerProject) {
                $this->historyRecorder->record($root->getCustomerProject(), 'build_started', $root->getContentName() ?? '', $root);
                if ($reservationConsumed > 0) {
                    $this->historyRecorder->record($root->getCustomerProject(), 'material_reservation_consumed', sprintf('%s beim Bauen ausgebucht', $reservationConsumed), $root);
                }
            }
            $this->entityManager->flush();

            return $root;
        });
    }

    /** @param array<string, mixed> $draft */
    private function appendPositionNode(array &$draft, ProjectPosition $position, ?string $parent, ?int $slotIndex = null): string
    {
        $content = $position->getSystemTemplate() ?? $position->getTemplateProject();
        if (!$content instanceof SystemTemplate && !$content instanceof Project) {
            throw new \RuntimeException('Die Projektposition verweist auf einen gelöschten Inhalt.');
        }
        $key = $this->appendContentNode($draft, $content, $parent, false, $position->getName(), $position->getId(), $position->getSourceSlot()?->getId(), $slotIndex);
        foreach ($position->getPartAssignments() as $assignment) {
            if ($assignment->getPart() instanceof Part) {
                $draft['nodes'][$key]['parts'][] = ['part_id' => $assignment->getPart()->getId(), 'quantity' => $assignment->getQuantity(), 'slot' => $assignment->getSourceSlot()?->getName() ?? 'Zubehör', 'slot_id' => $assignment->getSourceSlot()?->getId()];
            }
        }
        return $key;
    }

    /** @param array<string, mixed> $draft */
    private function appendContentNode(array &$draft, SystemTemplate|Project $content, ?string $parent, bool $needsConfiguration, ?string $name = null, ?int $positionId = null, ?int $sourceSlotId = null, ?int $slotIndex = null): string
    {
        $key = 'n'.count($draft['nodes']);
        $isSystem = $content instanceof SystemTemplate;
        $draft['nodes'][$key] = ['type' => $isSystem ? 'system' : 'project', 'content_id' => $content->getId(), 'name' => $name ?? $content->getName(), 'parent' => $parent, 'position_id' => $positionId, 'source_slot_id' => $sourceSlotId, 'slot_index' => $slotIndex, 'configured' => !$isSystem || !$needsConfiguration, 'parts' => []];

        return $key;
    }

    private function resolveAllowedChoice(SystemTemplateSlot $slot, string $choice): SystemTemplate|Project|Part|null
    {
        [$type, $id] = array_pad(explode('_', $choice, 2), 2, null);
        $class = match ($type) { 'system' => SystemTemplate::class, 'project' => Project::class, 'part' => Part::class, default => null };
        $content = null === $class ? null : $this->entityManager->find($class, (int) $id);
        return match (true) {
            $content instanceof SystemTemplate && $slot->getAllowedSystemTemplates()->contains($content) => $content,
            $content instanceof Project && $slot->getAllowedProjects()->contains($content) => $content,
            $content instanceof Part && $slot->getAllowedParts()->contains($content) => $content,
            default => null,
        };
    }

    /** @param array<string, mixed> $draft @return array<int, array{quantity: int, contributions: list<array{node: string, quantity: int}>}> */
    private function requirements(array $draft): array
    {
        $requirements = [];
        foreach ($draft['nodes'] as $key => $node) {
            $resolved = $this->resolveNode($draft, (string) $key);
            $projects = $resolved['content'] instanceof Project
                ? [$resolved['content']]
                : $resolved['content']->getBaseProjects();
            foreach ($projects as $project) {
                foreach ($project->getBomEntries() as $entry) {
                    if ($entry->getPart() instanceof Part && null !== $entry->getPart()->getId()) {
                        $this->addRequirement($requirements, $entry->getPart()->getId(), (int) ceil($entry->getQuantity()), (string) $key);
                    }
                }
            }
            foreach ($node['parts'] as $part) {
                $this->addRequirement($requirements, (int) $part['part_id'], (int) $part['quantity'], (string) $key, isset($part['slot_id']) ? (int) $part['slot_id'] : null);
            }
        }

        return $requirements;
    }

    /** @param array<int, array{quantity: int, contributions: list<array{node: string, quantity: int}>}> $requirements */
    private function addRequirement(array &$requirements, int $partId, int $quantity, string $node, ?int $sourceSlotId = null): void
    {
        if ($quantity < 1) { return; }
        $requirements[$partId] ??= ['quantity' => 0, 'contributions' => []];
        $requirements[$partId]['quantity'] += $quantity;
        $requirements[$partId]['contributions'][] = ['node' => $node, 'quantity' => $quantity, 'source_slot_id' => $sourceSlotId];
    }

    /** @param array<string, mixed> $draft */
    private function getCustomerProject(array $draft): ?CustomerProject
    {
        if (null === $draft['position_id']) { return null; }
        return $this->entityManager->find(ProjectPosition::class, $draft['position_id'])?->getCustomerProject();
    }

    private function lotBelongsToSite(PartLot $lot, StorageLocation $site): bool
    {
        $location = $lot->getStorageLocation();
        while ($location instanceof StorageLocation) {
            if ($location->getId() === $site->getId()) { return true; }
            $location = $location->getParent();
        }
        return false;
    }

    /** @param array<string, mixed> $draft @param array{items: list<array<string, mixed>>, complete: bool} $plan @param array<string, BuildInstance> $instances */
    private function consumeMaterials(array $draft, array $plan, array $instances, BuildInstance $root, User $user): int
    {
        $project = $root->getCustomerProject();
        $reservationConsumed = 0;
        foreach ($plan['items'] as $item) {
            $sources = [];
            $projectRemaining = $item['project_stock'];
            if ($project instanceof CustomerProject && $projectRemaining > 0) {
                foreach ($project->getMaterialAllocations() as $allocation) {
                    if ($allocation->getPart()?->getId() !== $item['part']->getId() || $projectRemaining < 1) { continue; }
                    $take = min($projectRemaining, $allocation->getQuantity());
                    $sources[] = ['quantity' => $take, 'lot' => $allocation->getSourcePartLot(), 'project' => true, 'serial' => $allocation->getSerialNumber()];
                    $projectRemaining -= $take;
                    if ($take === $allocation->getQuantity()) { $this->entityManager->remove($allocation); } else { $allocation->setQuantity($allocation->getQuantity() - $take); }
                }
            }
            foreach ($item['reserved_sources'] as $reservedSource) {
                $lot = $reservedSource['lot'];
                $quantity = $reservedSource['quantity'];
                $this->withdrawHelper->withdraw($lot, $quantity, sprintf('Reserviertes Material für Fertigung %s', $root->getDisplayIdentifier()));
                $this->reservationManager->consumeExact($reservedSource['reservation'], $quantity);
                $sources[] = ['quantity' => $quantity, 'lot' => $lot, 'project' => false, 'serial' => null];
                $reservationConsumed += $quantity;
            }
            $neededLots = $item['remaining'];
            foreach ($item['lots'] as $lotRow) {
                if ($neededLots < 1) { break; }
                $lot = $lotRow['lot'];
                $requested = (int) ($draft['lots'][(string) $item['part']->getId()][(string) $lot->getId()] ?? 0);
                if ($requested < 1) { continue; }
                $take = min($requested, $neededLots);
                $this->withdrawHelper->withdraw($lot, $take, sprintf('Fertigung %s', $root->getDisplayIdentifier()));
                $sources[] = ['quantity' => $take, 'lot' => $lot, 'project' => false, 'serial' => null];
                $neededLots -= $take;
            }
            if ($projectRemaining > 0 || $neededLots > 0) { throw new \RuntimeException('Die Materialauswahl deckt den Bedarf nicht vollständig.'); }
            $sourceIndex = 0;
            foreach ($item['contributions'] as $contribution) {
                $remaining = $contribution['quantity'];
                while ($remaining > 0) {
                    if (!isset($sources[$sourceIndex])) { throw new \RuntimeException('Interner Fehler bei der Materialzuordnung.'); }
                    $source = &$sources[$sourceIndex];
                    $take = min($remaining, $source['quantity']);
                    $sourceSlot = isset($contribution['source_slot_id'])
                        ? $this->entityManager->find(SystemTemplateSlot::class, $contribution['source_slot_id'])
                        : null;
                    $usage = (new BuildMaterialUsage())->setBuildInstance($instances[$contribution['node']])->setSourceSlot($sourceSlot)->setPart($item['part'])->setSourcePartLot($source['lot'])->setQuantity($take)->setFromProjectStock($source['project'])->setSerialNumber($source['serial'])->setAllocatedBy($user);
                    $this->entityManager->persist($usage);
                    $remaining -= $take;
                    $source['quantity'] -= $take;
                    if (0 === $source['quantity']) { ++$sourceIndex; }
                }
            }
        }

        return $reservationConsumed;
    }
}
